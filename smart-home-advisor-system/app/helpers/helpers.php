<?php
declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function route(string $name, array $params = []): string
{
    $params = array_merge(['page' => $name], $params);
    return 'index.php?' . http_build_query($params);
}

function redirect(string $name, array $params = []): void
{
    header('Location: ' . route($name, $params));
    exit;
}

function money(float $value): string
{
    return 'RM ' . number_format($value, 0);
}

function flash(?string $message = null, string $type = 'success'): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function checked(bool $state): string
{
    return $state ? 'checked' : '';
}

