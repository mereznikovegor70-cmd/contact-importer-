<?php
declare(strict_types=1);
return [
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'contacts', 'user' => 'contacts', 'password' => 'CHANGE_ME'],
    // Полный внешний адрес public/index.php, без завершающего слеша.
    'app_url' => 'https://example.ru/contacts/index.php',
    'admin_login' => 'admin',
    // Получить командой: php bin/password.php
    'admin_password_hash' => 'CHANGE_ME',
    // Случайная строка не короче 32 символов. Нужна только для install.php.
    'setup_key' => 'CHANGE_ME',
    'timezone' => 'Asia/Yekaterinburg',
    'max_upload_bytes' => 20 * 1024 * 1024,
    'max_rows' => 20000,
    'max_columns' => 100,
    'max_cells' => 300000,
    'phone_action' => 'form', // form — карточка контакта; dial — сразу tel:
];
