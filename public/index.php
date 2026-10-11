<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($path === '/api/health' && $method === 'GET') {
    json_response(['status' => 'ok', 'app' => $config['name'], 'mode' => 'shared-hosting']);
}

if ($path === '/api/health/ready' && $method === 'GET') {
    try {
        require_once PLUQI_ROOT . '/config/database.php';
        database()->query('SELECT 1');
        json_response(['status' => 'ready', 'database' => 'ok']);
    } catch (Throwable $e) {
        json_response(['status' => 'not_ready', 'database' => 'unavailable'], 503);
    }
}

if ($path === '/' && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    $name = htmlspecialchars($config['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $name . '</title><style>body{margin:0;background:#090909;color:#f5f5f5;font:16px system-ui,sans-serif;display:grid;min-height:100vh;place-items:center}.wrap{max-width:680px;padding:32px}h1{font-size:48px;margin:0 0 12px}p{color:#aaa;line-height:1.6}.tag{display:inline-block;border:1px solid #333;border-radius:999px;padding:6px 10px;font-size:12px;color:#ccc}</style></head><body><main class="wrap"><span class="tag">SHARED HOSTING EDITION</span><h1>' . $name . '</h1><p>Fondasi aplikasi berhasil dijalankan. Katalog dan transaksi belum diaktifkan sampai integrasi provider, pembayaran, dan pengujian selesai.</p></main></body></html>';
    exit;
}

json_response(['error' => 'not_found'], 404);
