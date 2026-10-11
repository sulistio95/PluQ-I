<?php
declare(strict_types=1);

function database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('DB_PORT') ?: 3306);
    $name = getenv('DB_DATABASE') ?: '';
    $user = getenv('DB_USERNAME') ?: '';
    $pass = getenv('DB_PASSWORD') ?: '';
    $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

    if ($name === '' || $user === '') {
        throw new RuntimeException('Database configuration is incomplete.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    return $pdo;
}
