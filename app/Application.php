<?php
declare(strict_types=1);
namespace Contacts;

use PDO;

final class Application
{
    public function __construct(private PDO $db, private array $config, private string $storage) {}

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->db->prepare($sql); $stmt->execute($params); return $stmt;
    }
    private function redirect(string $action, array $params = []): never
    {
        header('Location: ' . View::url($action, $params), true, 303); exit;
    }
    private function notice(string $message): void { $_SESSION['notice'] = $message; }
    private function post(string $name, string $default = ''): string
    {
        $v = $_POST[$name] ?? $default;
        if (!is_string($v)) { throw new \InvalidArgumentException('Некорректное поле формы.'); }
        return trim($v);
    }
    private function id(): int { return max(0, (int)($_GET['id'] ?? 0)); }
    private function import(int $id): array
    {
        $record = $this->query('SELECT * FROM ci_imports WHERE id=?', [$id])->fetch();
        if (!$record) { throw new \InvalidArgumentException('Загрузка не найдена.'); }
        return $record;
    }

    public function run(): void
    {
        $action = is_string($_GET['a'] ?? null) ? $_GET['a'] : 'home';
        $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
        if ($action === 'feed') { $this->feed(); return; }
        if ($isPost && (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf']))) {
            http_response_code(403); throw new \InvalidArgumentException('Сессия формы истекла. Обновите страницу.');
        }
        if (empty($_SESSION['authenticated'])) {
            if ($action === 'login' && $isPost) { $this->login(); return; }
            View::render('login', ['title' => 'Вход']); return;
        }
        $postActions = ['logout', 'upload', 'configure', 'preview', 'commit', 'note_save', 'feed_create', 'feed_revoke', 'template_delete'];
        if (in_array($action, $postActions, true) && !$isPost) { http_response_code(405); throw new \InvalidArgumentException('Для этого действия требуется отправка формы.'); }
        switch ($action) {
            case 'logout': $_SESSION = []; session_regenerate_id(true); $this->redirect('home');
            case 'upload': $this->upload(); break;
            case 'configure': $this->configure(); break;
            case 'mapping': $this->mapping(); break;
            case 'preview': $this->preview(); break;
            case 'commit': $this->commit(); break;
            case 'table': $this->table(); break;
            case 'export': $this->export(); break;
            case 'note': $this->note(); break;
            case 'note_save': $this->saveNote(); break;
            case 'feed_create': $this->createFeed(); break;
            case 'feed_revoke':
                $this->query('DELETE FROM ci_feeds WHERE import_id=?', [$this->id()]);
                unset($_SESSION['feed_urls'][$this->id()]);
                $this->notice('Ссылка отозвана.'); $this->redirect('table', ['id' => $this->id()]);
            case 'templates': View::render('templates', ['title' => 'Шаблоны', 'templates' => $this->query('SELECT * FROM ci_templates ORDER BY id DESC')->fetchAll()]); break;
            case 'template_delete':
                $this->query('DELETE FROM ci_templates WHERE id=?', [$this->id()]);
                $this->notice('Шаблон удалён. Загруженные контакты сохранены.'); $this->redirect('templates');
            case 'home':
                View::render('home', ['title' => 'Базы контактов', 'imports' => $this->query('SELECT * FROM ci_imports ORDER BY id DESC LIMIT 100')->fetchAll(), 'stats' => $this->query('SELECT COUNT(*) AS total, COALESCE(SUM(row_count),0) AS leads FROM ci_imports')->fetch(), 'config' => $this->config]); break;
            default: http_response_code(404); throw new \InvalidArgumentException('Страница не найдена.');
        }
    }

    private function login(): void
    {
        $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown'); $now = time();
        $this->query('INSERT IGNORE INTO ci_login_attempts (ip_hash,attempts,window_started) VALUES (?,0,?)', [$ip, $now]);
        $this->query('UPDATE ci_login_attempts SET attempts=0,window_started=? WHERE ip_hash=? AND window_started<?', [$now, $ip, $now - 900]);
        $this->query('UPDATE ci_login_attempts SET attempts=attempts+1 WHERE ip_hash=?', [$ip]);
        $attempts = (int)$this->query('SELECT attempts FROM ci_login_attempts WHERE ip_hash=?', [$ip])->fetchColumn();
        if ($attempts > 10) { http_response_code(429); View::render('login', ['title' => 'Вход', 'error' => 'Слишком много попыток. Повторите через 15 минут.']); return; }
        if ($this->post('login') !== $this->config['admin_login'] || !password_verify($this->post('password'), $this->config['admin_password_hash'])) {
            http_response_code(401); View::render('login', ['title' => 'Вход', 'error' => 'Неверный логин или пароль.']); return;
        }
        $this->query('DELETE FROM ci_login_attempts WHERE ip_hash=?', [$ip]);
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true; $_SESSION['csrf'] = bin2hex(random_bytes(32)); $_SESSION['last_seen'] = time();
        $this->redirect('home');
    }

    private function stagePath(string $suffix): string
    {
        $token = $_SESSION['stage'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{48}$/', $token)) { throw new \InvalidArgumentException('Предпросмотр истёк. Загрузите файл снова.'); }
        return $this->storage . '/stage-' . $token . $suffix;
    }
    private function stage(): array
    {
        $path = $this->stagePath('.json');
        if (!is_file($path) || filemtime($path) < time() - 7200) { throw new \InvalidArgumentException('Предпросмотр истёк. Загрузите файл снова.'); }
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
    private function saveStage(array $stage): void
    {
        if (file_put_contents($this->stagePath('.json'), json_encode($stage, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) { throw new \RuntimeException('Не удалось сохранить предпросмотр. Проверьте права storage.'); }
    }
    private function removeStage(): void
    {
        if (isset($_SESSION['stage'])) {
            foreach (['.json', '.source'] as $suffix) { $path = $this->stagePath($suffix); if (is_file($path)) { unlink($path); } }
            unset($_SESSION['stage']);
        }
    }
    private function upload(): void
    {
        foreach (glob($this->storage . '/stage-*') ?: [] as $stale) {
            if (is_file($stale) && filemtime($stale) < time() - 7200) { unlink($stale); }
        }
        $this->removeStage(); $_SESSION['stage'] = bin2hex(random_bytes(24));
        $google = $this->post('google_url');
        $hasFile = isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($google !== '' && $hasFile) { throw new \InvalidArgumentException('Выберите один источник: файл или Google-ссылку.'); }
        try {
            if ($google !== '') {
                $data = GoogleSource::fetch($google, $this->config['max_upload_bytes']);
                if (file_put_contents($this->stagePath('.source'), $data, LOCK_EX) === false) { throw new \RuntimeException('Нет прав на запись в storage.'); }
                $name = 'Google-таблица'; $ext = 'csv'; $source = $google; $type = 'google';
            } else {
                $file = $_FILES['file'] ?? null;
                if (!$file || ($file['error'] ?? -1) !== UPLOAD_ERR_OK) { throw new \InvalidArgumentException('Файл не загружен. Проверьте размер файла и лимиты PHP.'); }
                if ($file['size'] > $this->config['max_upload_bytes']) { throw new \InvalidArgumentException('Файл превышает лимит размера.'); }
                $name = mb_substr(basename(str_replace('\\', '/', $file['name'])), 0, 200);
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, ['xls', 'xlsx', 'csv'], true)) { throw new \InvalidArgumentException('Выберите XLS, XLSX или CSV.'); }
                if (!move_uploaded_file($file['tmp_name'], $this->stagePath('.source'))) { throw new \RuntimeException('Нет прав на запись в storage.'); }
                $source = $name; $type = $ext;
            }
            $parsed = (new TableReader($this->config))->read($this->stagePath('.source'), $ext, 0, $this->post('encoding', 'auto'), $this->post('delimiter', 'auto'));
            if (!$parsed['rows']) { throw new \InvalidArgumentException('Таблица пуста.'); }
            $this->saveStage($parsed + ['name' => $this->post('name') ?: $name, 'source' => $source, 'type' => $type, 'extension' => $ext, 'header_row' => 1, 'has_header' => true]);
        } catch (\Throwable $e) { $this->removeStage(); throw $e; }
        $this->redirect('mapping');
    }
    private function configure(): void
    {
        $stage = $this->stage();
        $parsed = (new TableReader($this->config))->read($this->stagePath('.source'), $stage['extension'], (int)$this->post('sheet', '0'), $this->post('encoding', 'auto'), $this->post('delimiter', 'auto'));
        $stage = array_replace($stage, $parsed, ['header_row' => (int)$this->post('header_row', '1'), 'has_header' => isset($_POST['has_header'])]);
        TableReader::table($stage['rows'], $stage['header_row'], $stage['has_header']);
        unset($stage['mapping'], $stage['template_name']);
        $this->saveStage($stage); $this->redirect('mapping');
    }
    private function mapping(): void
    {
        $stage = $this->stage(); $structureWarning = null;
        try { $table = TableReader::table($stage['rows'], $stage['header_row'], $stage['has_header']); }
        catch (\InvalidArgumentException $e) {
            $structureWarning = $e->getMessage() . ' Выберите другой лист или начальную строку.';
            $table = ['headers' => [], 'data' => []];
        }
        $signature = Normalizer::signature($table['headers']);
        $templates = $this->query('SELECT * FROM ci_templates WHERE signature=? ORDER BY id DESC', [$signature])->fetchAll();
        $mapping = $stage['mapping'] ?? ($templates ? json_decode($templates[0]['mapping_json'], true) : Normalizer::guess($table['headers']));
        if (isset($_GET['template'])) {
            foreach ($templates as $template) { if ((int)$template['id'] === (int)$_GET['template']) { $mapping = json_decode($template['mapping_json'], true); } }
        }
        View::render('mapping', ['title' => 'Сопоставление колонок', 'stage' => $stage, 'table' => $table, 'mapping' => $mapping, 'templates' => $templates, 'structureWarning' => $structureWarning]);
    }
    private function preview(): void
    {
        $stage = $this->stage(); $table = TableReader::table($stage['rows'], $stage['header_row'], $stage['has_header']);
        $stage['mapping'] = Normalizer::validateMapping(is_array($_POST['mapping'] ?? null) ? $_POST['mapping'] : [], count($table['headers']));
        $stage['template_name'] = mb_substr($this->post('template_name'), 0, 150);
        $this->saveStage($stage);
        $preview = array_map(fn($r) => Normalizer::row($r['values'], $stage['mapping']), array_slice($table['data'], 0, 10));
        View::render('preview', ['title' => 'Проверка импорта', 'stage' => $stage, 'count' => count($table['data']), 'preview' => $preview]);
    }
    private function commit(): void
    {
        $stage = $this->stage(); $table = TableReader::table($stage['rows'], $stage['header_row'], $stage['has_header']);
        $mapping = Normalizer::validateMapping($stage['mapping'] ?? [], count($table['headers']));
        if (count($table['data']) > $this->config['max_rows']) { throw new \InvalidArgumentException('Превышен лимит строк импорта.'); }
        $json = fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->beginTransaction();
        try {
            $this->query('INSERT INTO ci_imports (name,source_type,source_name,headers_json,mapping_json,row_count) VALUES (?,?,?,?,?,?)', [mb_substr($stage['name'], 0, 255), $stage['type'], $stage['source'], $json($table['headers']), $json($mapping), count($table['data'])]);
            $id = (int)$this->db->lastInsertId();
            $insert = $this->db->prepare('INSERT INTO ci_leads (import_id,source_row,raw_json,company,address,phones,email,website) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($table['data'] as $r) {
                $row = Normalizer::row($r['values'], $mapping);
                foreach ($row as $value) { if (strlen($value) > 65535) { throw new \InvalidArgumentException('Строка ' . $r['number'] . ': объединённое поле превышает 65535 байт. Выберите меньше исходных колонок.'); } }
                $insert->execute([$id, $r['number'], $json($r['values']), $row['company'], $row['address'], $row['phones'], $row['email'], $row['website']]);
            }
            if (($stage['template_name'] ?? '') !== '') {
                $this->query('INSERT INTO ci_templates (name,signature,headers_json,mapping_json) VALUES (?,?,?,?)', [$stage['template_name'], Normalizer::signature($table['headers']), $json($table['headers']), $json($mapping)]);
            }
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        $this->removeStage(); $this->notice('Импорт завершён: ' . count($table['data']) . ' контактов.'); $this->redirect('table', ['id' => $id]);
    }
    private function table(): void
    {
        $import = $this->import($this->id()); $raw = ($_GET['view'] ?? '') === 'raw';
        $search = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 200) : '';
        $where = 'import_id=?'; $params = [$import['id']];
        if ($search !== '') {
            $where .= $raw ? ' AND raw_json LIKE ?' : " AND CONCAT_WS(' ',company,address,phones,email,website) LIKE ?";
            $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        }
        $count = (int)$this->query('SELECT COUNT(*) FROM ci_leads WHERE ' . $where, $params)->fetchColumn();
        $pages = max(1, (int)ceil($count / 50)); $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
        $rows = $this->query('SELECT * FROM ci_leads WHERE ' . $where . ' ORDER BY id LIMIT 50 OFFSET ' . (($page - 1) * 50), $params)->fetchAll();
        $activeFeed = (bool)$this->query('SELECT id FROM ci_feeds WHERE import_id=? LIMIT 1', [$import['id']])->fetchColumn();
        View::render('table', ['title' => $import['name'], 'import' => $import, 'raw' => $raw, 'search' => $search, 'count' => $count, 'pages' => $pages, 'page' => $page, 'rows' => $rows, 'activeFeed' => $activeFeed, 'feedUrl' => $_SESSION['feed_urls'][$import['id']] ?? null, 'phoneAction' => $this->config['phone_action']]);
    }
    private function export(): void
    {
        $import = $this->import($this->id()); $format = $_GET['format'] ?? 'csv';
        if (!in_array($format, ['xls', 'csv'], true)) { throw new \InvalidArgumentException('Неизвестный формат экспорта.'); }
        $rows = $this->query('SELECT company,address,phones,email,website FROM ci_leads WHERE import_id=? ORDER BY id', [$import['id']]);
        session_write_close();
        if ($format === 'xls') {
            $path = tempnam($this->storage, 'export-');
            try { Exporter::xls($rows, $path); header('Content-Type: application/vnd.ms-excel'); header('Content-Disposition: attachment; filename="contacts-' . $import['id'] . '.xls"'); readfile($path); }
            finally { if (is_file($path)) { unlink($path); } }
        } else {
            header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="contacts-' . $import['id'] . '.csv"');
            Exporter::csv($rows, fopen('php://output', 'wb'));
        }
    }
    private function createFeed(): void
    {
        $import = $this->import($this->id()); $token = bin2hex(random_bytes(32));
        $base = $this->config['app_url'];
        if (!filter_var($base, FILTER_VALIDATE_URL) || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true) || str_contains($base, '?') || str_contains($base, '#')) { throw new \InvalidArgumentException('Укажите полный app_url без параметров в config.php.'); }
        $this->db->beginTransaction();
        try {
            $this->query('DELETE FROM ci_feeds WHERE import_id=?', [$import['id']]);
            $this->query('INSERT INTO ci_feeds (import_id,token_hash) VALUES (?,?)', [$import['id'], hash('sha256', $token)]);
            $this->db->commit();
        } catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
        $_SESSION['feed_urls'][$import['id']] = $base . '?a=feed&token=' . $token;
        $this->notice('CSV-ссылка создана. Сохраните её: после выхода из аккаунта можно будет только выпустить новую.');
        $this->redirect('table', ['id' => $import['id']]);
    }
    private function feed(): void
    {
        $token = $_GET['token'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Ссылка не найдена'; return; }
        $id = $this->query('SELECT import_id FROM ci_feeds WHERE token_hash=?', [hash('sha256', $token)])->fetchColumn();
        if (!$id) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Ссылка не найдена или отозвана'; return; }
        session_write_close();
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: inline; filename="contacts.csv"'); header('Referrer-Policy: no-referrer');
        $rows = $this->query('SELECT company,address,phones,email,website FROM ci_leads WHERE import_id=? ORDER BY id', [$id]);
        Exporter::csv($rows, fopen('php://output', 'wb'), false);
    }
    private function lead(): array
    {
        $lead = $this->query('SELECT * FROM ci_leads WHERE id=?', [$this->id()])->fetch();
        if (!$lead) { throw new \InvalidArgumentException('Контакт не найден.'); }
        return $lead;
    }
    private function note(): void
    {
        $lead = $this->lead(); $phones = array_filter(explode('; ', $lead['phones']));
        $phone = is_string($_GET['phone'] ?? null) && in_array($_GET['phone'], $phones, true) ? $_GET['phone'] : (reset($phones) ?: '');
        View::render('note', ['title' => 'Карточка контакта', 'lead' => $lead, 'phone' => $phone, 'notes' => $this->query('SELECT * FROM ci_notes WHERE lead_id=? ORDER BY id DESC', [$lead['id']])->fetchAll()]);
    }
    private function date(string $key): ?string
    {
        $value = $this->post($key); if ($value === '') { return null; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value || $value < '1000-01-01' || $value > '9999-12-31') { throw new \InvalidArgumentException('Некорректная дата.'); }
        return $value;
    }
    private function saveNote(): void
    {
        $lead = $this->lead(); $phone = $this->post('phone');
        if ($phone !== '' && !in_array($phone, explode('; ', $lead['phones']), true)) { throw new \InvalidArgumentException('Телефон не принадлежит контакту.'); }
        $comment = $this->post('comment');
        if (mb_strlen($comment) > 10000 || strlen($phone) > 100) { throw new \InvalidArgumentException('Слишком длинный комментарий или телефон.'); }
        $this->query('INSERT INTO ci_notes (lead_id,phone,contact_date,comment,followup_date) VALUES (?,?,?,?,?)', [$lead['id'], $phone, $this->date('contact_date'), $comment, $this->date('followup_date')]);
        $this->notice('Комментарий сохранён.'); $this->redirect('note', ['id' => $lead['id'], 'phone' => $phone]);
    }
}
