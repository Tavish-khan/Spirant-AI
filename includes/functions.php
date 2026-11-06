<?php

declare(strict_types=1);

function spirant_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_samesite', 'Lax');
        session_start();
    }
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function current_user(): ?array
{
    spirant_session_start();

    if (empty($_SESSION['user'])) {
        return null;
    }

    return $_SESSION['user'];
}

function spirant_base_path(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/';
    $baseDir = rtrim(dirname($scriptName), '/');

    if (preg_match('#/api$#i', $baseDir) === 1) {
        $baseDir = preg_replace('#/api$#i', '', $baseDir);
    }

    if ($baseDir === '.' || $baseDir === '\\' || $baseDir === '') {
        return '';
    }

    return $baseDir;
}

function spirant_public_path(string $path): string
{
    $basePath = spirant_base_path();
    $normalized = '/' . ltrim($path, '/');

    if (preg_match('#^/api(?=/uploads/)#i', $normalized) === 1) {
        $normalized = preg_replace('#^/api#i', '', $normalized);
    }

    return $basePath === '' ? $normalized : $basePath . $normalized;
}

function require_login(): void
{
    spirant_session_start();

    if (empty($_SESSION['user'])) {
        redirect('login.php');
    }
}

function require_guest(): void
{
    spirant_session_start();

    if (!empty($_SESSION['user'])) {
        redirect('dashboard.php');
    }
}

function csrf_token(): string
{
    spirant_session_start();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function validate_csrf(string $token): bool
{
    spirant_session_start();

    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function json_response(bool $success, string $message, array $data = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ]);
    exit;
}

function sanitize_text(?string $value): string
{
    return trim((string) $value);
}

function generate_conversation_title(string $message): string
{
    $clean = preg_replace('/\s+/', ' ', trim(strip_tags($message)));
    if ($clean === '') {
        return 'New Conversation';
    }

    $title = mb_substr($clean, 0, 45, 'UTF-8');
    return $title !== '' ? $title : 'New Conversation';
}

function format_datetime(?string $value): string
{
    if (!$value) {
        return 'Just now';
    }

    $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M d, Y h:i A');
}

function format_relative_time(string $value): string
{
    $date = new DateTimeImmutable($value);
    $now = new DateTimeImmutable('now');
    $diff = $now->getTimestamp() - $date->getTimestamp();

    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h';
    }

    return floor($diff / 86400) . 'd';
}

function success_flash(string $message): void
{
    spirant_session_start();
    $_SESSION['flash_success'] = $message;
}

function error_flash(string $message): void
{
    spirant_session_start();
    $_SESSION['flash_error'] = $message;
}

function flash_message(string $type): ?string
{
    spirant_session_start();
    $key = $type === 'success' ? 'flash_success' : 'flash_error';
    $message = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);
    return $message;
}
