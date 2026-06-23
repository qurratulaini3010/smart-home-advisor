<?php
declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(self::token()) . '">';
    }

    public static function verify(): void
    {
        $sent = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
            http_response_code(419);
            exit('Security token expired. Please go back and try again.');
        }
    }
}

