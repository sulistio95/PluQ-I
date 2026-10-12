<?php
declare(strict_types=1);

function admin_request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    // Forwarded headers are client-spoofable unless a trusted proxy sanitizes them.
    // Honor X-Forwarded-Proto only when the deployment explicitly opts in.
    if (strtolower(trim((string) (getenv('ADMIN_TRUST_PROXY_HTTPS') ?: 'false'))) !== 'true') {
        return false;
    }
    return strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
}

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = admin_request_is_https();
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

/** Only privileged admin roles may change supplier mappings or Drive settings. */
function admin_can_manage_settings(array $user): bool
{
    return in_array((string) ($user['role'] ?? ''), ['SUPER_ADMIN', 'ADMIN'], true);
}

function admin_escape(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}


/**
 * Throttle admin authentication by both remote IP and normalized username.
 * Requires migration 008_admin_login_rate_limit.sql.
 */
function admin_login_is_limited(PDO $pdo, string $ipAddress, string $username): bool
{
    $ipAddress = substr($ipAddress, 0, 45);
    $usernameHash = hash('sha256', mb_strtolower(trim($username), 'UTF-8'));
    $stmt = $pdo->prepare("SELECT
        SUM(CASE WHEN ip_address = :ip_count THEN 1 ELSE 0 END) AS ip_attempts,
        SUM(CASE WHEN username_hash = :user_count THEN 1 ELSE 0 END) AS username_attempts
        FROM admin_login_attempts
        WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
          AND (ip_address = :ip_filter OR username_hash = :user_filter)");
    $stmt->execute([
        'ip_count' => $ipAddress,
        'user_count' => $usernameHash,
        'ip_filter' => $ipAddress,
        'user_filter' => $usernameHash,
    ]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return (int) ($counts['ip_attempts'] ?? 0) >= 10
        || (int) ($counts['username_attempts'] ?? 0) >= 10;
}

function admin_login_record_failure(PDO $pdo, string $ipAddress, string $username): void
{
    $stmt = $pdo->prepare('INSERT INTO admin_login_attempts (ip_address, username_hash, attempted_at) VALUES (:ip, :username_hash, NOW())');
    $stmt->execute([
        'ip' => substr($ipAddress, 0, 45),
        'username_hash' => hash('sha256', mb_strtolower(trim($username), 'UTF-8')),
    ]);
    // Bounded cleanup; never scan/delete recent attempt rows.
    $pdo->exec('DELETE FROM admin_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 2 DAY) LIMIT 500');
}

function admin_login_clear_failures(PDO $pdo, string $ipAddress, string $username): void
{
    $stmt = $pdo->prepare('DELETE FROM admin_login_attempts WHERE ip_address = :ip OR username_hash = :username_hash');
    $stmt->execute([
        'ip' => substr($ipAddress, 0, 45),
        'username_hash' => hash('sha256', mb_strtolower(trim($username), 'UTF-8')),
    ]);
}
