<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';
$config=require (getenv('CONTACTS_CONFIG') ?: $root.'/config.php');
$db=Contacts\Database::connect($config);
$db->exec(file_get_contents($root.'/database/schema.sql'));
echo "Schema installed. Configure admin_password_hash before opening the app.\n";
