<?php
declare(strict_types=1);
namespace Contacts;

final class View
{
    public static function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function url(string $action, array $args = []): string { return 'index.php?' . http_build_query(['a' => $action] + $args); }
    public static function csrf(): string { return '<input type="hidden" name="csrf" value="' . self::e($_SESSION['csrf']) . '">'; }
    public static function render(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $e = self::e(...); $url = self::url(...);
        ob_start();
        require dirname(__DIR__) . '/views/' . $template . '.php';
        $content = ob_get_clean();
        require dirname(__DIR__) . '/views/layout.php';
    }
}
