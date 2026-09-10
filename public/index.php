<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
$root = dirname(__DIR__);
$configPath = getenv('CONTACTS_CONFIG') ?: $root . '/config.php';
if (!is_file($root . '/vendor/autoload.php') || !is_file($configPath)) {
    http_response_code(503);
    echo '<!doctype html><html lang="ru"><meta charset="utf-8"><title>Настройка приложения</title><h1>Требуется настройка</h1><p>Загрузите папку vendor из архива. Скопируйте config.example.php в config.php и укажите подключение к БД. Инструкция находится в README.md.</p></html>'; exit;
}
require $root . '/vendor/autoload.php';
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'UTC');
session_name('contacts_' . substr(hash('sha256', $configPath . $config['app_url']), 0, 16));
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'secure' => ($_SERVER['HTTPS'] ?? '') === 'on', 'samesite' => 'Lax', 'path' => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/']);
session_start();
if (!empty($_SESSION['last_seen']) && $_SESSION['last_seen'] < time() - 7200) { $_SESSION = []; session_regenerate_id(true); }
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
if (!empty($_SESSION['authenticated'])) { $_SESSION['last_seen'] = time(); }
try {
    if (($config['admin_password_hash'] ?? 'CHANGE_ME') === 'CHANGE_ME') {
        Contacts\View::render('error', ['title' => 'Настройка', 'message' => 'Укажите setup_key в config.php, затем откройте install.php для установки.']); exit;
    }
    $db = Contacts\Database::connect($config);
    (new Contacts\Application($db, $config, $root . '/storage'))->run();
} catch (InvalidArgumentException $e) {
    if (http_response_code() < 400) { http_response_code(422); }
    Contacts\View::render('error', ['title' => 'Проверьте данные', 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    $ref = bin2hex(random_bytes(4)); error_log('Contacts [' . $ref . '] ' . $e);
    http_response_code(500);
    Contacts\View::render('error', ['title' => 'Не удалось выполнить действие', 'message' => 'Проверьте формат файла, настройки базы и доступность источника. Код для журнала ошибок хостинга: ' . $ref]);
}
