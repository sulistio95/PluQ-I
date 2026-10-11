<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(2);
}
if ($argc !== 3) {
    fwrite(STDERR, "Usage: ADMIN_INITIAL_PASSWORD='strong-password' php bin/create-admin.php <username> <email>\n");
    exit(2);
}
$username = trim((string) $argv[1]);
$email = trim((string) $argv[2]);
$password = (string) getenv('ADMIN_INITIAL_PASSWORD');
if (!preg_match('/^[a-zA-Z0-9_.-]{3,100}$/D', $username)) {
    fwrite(STDERR, "Username must be 3-100 characters using letters, numbers, dot, underscore or hyphen.\n");
    exit(2);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
    fwrite(STDERR, "A valid email address is required.\n");
    exit(2);
}
if (strlen($password) < 14 || strlen($password) > 4096) {
    fwrite(STDERR, "Set ADMIN_INITIAL_PASSWORD to a unique password of at least 14 characters.\n");
    exit(2);
}
$pdo = database();
$stmt = $pdo->prepare("INSERT INTO admin_users (username,email,password_hash,role,status) VALUES (:username,:email,:hash,'SUPER_ADMIN','ACTIVE')");
try {
    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
} catch (PDOException $e) {
    if ((string) $e->getCode() === '23000') {
        fwrite(STDERR, "Username or email already exists. No account was changed.\n");
        exit(1);
    }
    throw $e;
}
fwrite(STDOUT, "Admin account created. Remove ADMIN_INITIAL_PASSWORD from the shell environment immediately.\n");
