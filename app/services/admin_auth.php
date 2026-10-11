<?php
declare(strict_types=1);

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    session_name('PLUQI_ADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['admin_csrf']) || !is_string($_SESSION['admin_csrf'])) {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf'];
}

function admin_csrf_valid(mixed $token): bool
{
    return is_string($token) && isset($_SESSION['admin_csrf'])
        && is_string($_SESSION['admin_csrf']) && hash_equals($_SESSION['admin_csrf'], $token);
}

function admin_current_user(PDO $pdo): ?array
{
    $id = filter_var($_SESSION['admin_user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) return null;
    $stmt = $pdo->prepare("SELECT id,username,email,role FROM admin_users WHERE id=:id AND status='ACTIVE' LIMIT 1");
    $stmt->execute(['id' => $id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        unset($_SESSION['admin_user_id']);
        return null;
    }
    return $user;
}

function admin_escape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
