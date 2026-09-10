<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
$root = dirname(__DIR__);
$configPath = getenv('CONTACTS_CONFIG') ?: $root . '/config.php';
$message = ''; $installed = false; $disabled = false;
session_name('contacts_setup');
ini_set('session.use_strict_mode','1');
session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>($_SERVER['HTTPS']??'')==='on']);
session_start(); $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
try {
    if (!is_file($configPath) || !is_file($root.'/vendor/autoload.php')) { throw new RuntimeException('Загрузите vendor и создайте config.php по образцу config.example.php.'); }
    $config = require $configPath;
    if (($config['admin_password_hash']??'CHANGE_ME') !== 'CHANGE_ME') { $disabled=true; throw new RuntimeException('Установка уже завершена. Войдите в приложение.'); }
    if (strlen($config['setup_key']??'')<32) { throw new RuntimeException('Задайте в config.php случайный setup_key длиной минимум 32 символа.'); }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if (!is_string($_POST['csrf']??null) || !hash_equals($_SESSION['csrf'],$_POST['csrf'])) { throw new RuntimeException('Обновите страницу и повторите отправку.'); }
        if (!is_string($_POST['setup_key']??null) || !hash_equals($config['setup_key'],$_POST['setup_key'])) { throw new RuntimeException('Неверный ключ установки.'); }
        $password=$_POST['password']??'';
        if (!is_string($password) || strlen($password)<12 || strlen($password)>72 || trim($password)!==$password) { throw new RuntimeException('Пароль: от 12 до 72 байт, без пробелов в начале и конце.'); }
        $required=['pdo_mysql','mbstring','curl','zip','gd','xml','dom','fileinfo'];
        foreach ($required as $ext) { if (!extension_loaded($ext)) { throw new RuntimeException('Включите расширение PHP: '.$ext); } }
        if (PHP_VERSION_ID<80300 || PHP_VERSION_ID>=80400) { throw new RuntimeException('Выберите PHP 8.3 в панели хостинга.'); }
        if (!is_writable($root.'/storage') || !is_writable($configPath)) { throw new RuntimeException('На время установки нужны права записи в config.php и storage.'); }
        require $root.'/vendor/autoload.php';
        $db=Contacts\Database::connect($config);
        $db->exec(file_get_contents($root.'/database/schema.sql'));
        $config['admin_password_hash']=password_hash($password,PASSWORD_DEFAULT);
        $config['setup_key']='';
        $text="<?php\ndeclare(strict_types=1);\nreturn ".var_export($config,true).";\n";
        $handle=fopen($configPath,'c+');
        if (!$handle || !flock($handle,LOCK_EX)) { throw new RuntimeException('Не удалось записать config.php.'); }
        try {
            // Recheck under lock to avoid a second concurrent setup replacing credentials.
            rewind($handle); $current=stream_get_contents($handle);
            if (!preg_match('/[\x27\x22]admin_password_hash[\x27\x22]\s*=>\s*[\x27\x22]CHANGE_ME[\x27\x22]/', $current)) { throw new RuntimeException('Настройки уже изменены. Обновите страницу.'); }
            rewind($handle); if (fwrite($handle,$text)!==strlen($text)) { throw new RuntimeException('Не удалось сохранить настройки полностью. Проверьте свободное место.'); }
            ftruncate($handle,strlen($text)); fflush($handle);
        } finally { flock($handle,LOCK_UN); fclose($handle); }
        if (function_exists('opcache_invalidate')) { opcache_invalidate($configPath,true); }
        $installed=true; $disabled=true; $message='Установка завершена. Логин: '.$config['admin_login'].'. Пароль — заданный вами.';
    }
} catch (PDOException $ex) { error_log((string)$ex); $message='Не удалось подключиться к MySQL или создать таблицы. Проверьте db в config.php и права пользователя базы.'; }
catch (Throwable $ex) { $message=$ex->getMessage(); }
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Установка · Контакт</title><link rel="stylesheet" href="assets/app.css"></head><body><main><section class="card auth"><div class="eyebrow">ПЕРВЫЙ ЗАПУСК</div><h1>Установка приложения</h1><?php if($message): ?><div class="<?= $installed?'notice':'error' ?>"><?= $e($message) ?></div><?php endif ?><?php if(!$disabled): ?><p class="muted">База данных должна быть создана в панели хостинга. Подключение задаётся в config.php.</p><form method="post" class="stack"><input type="hidden" name="csrf" value="<?= $e($_SESSION['csrf']) ?>"><label>Ключ установки из config.php<input type="password" name="setup_key" autocomplete="off" required></label><label>Новый пароль администратора<input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><button class="button primary">Создать таблицы и завершить установку</button></form><?php else: ?><a class="button primary" href="index.php">Перейти ко входу →</a><?php endif ?></section></main></body></html>
