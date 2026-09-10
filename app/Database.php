<?php
declare(strict_types=1);
namespace Contacts;

use PDO;

final class Database
{
    public static function connect(array $config): PDO
    {
        $d = $config['db'];
        if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $d['name'])) {
            throw new \RuntimeException('Некорректное имя базы данных.');
        }
        return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], $d['port'], $d['name']), $d['user'], $d['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
