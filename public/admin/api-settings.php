<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/admin_auth.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
admin_session_start();
$pdo = database();
$user = admin_current_user($pdo);
if (!$user) { header('Location: /admin/'); exit; }
if (!admin_can_manage_settings($user)) { http_response_code(403); exit('Forbidden'); }
?><!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pengaturan API - PluQ-I</title><body><main><h1>Pengaturan API</h1><p>Halaman pengaturan API sedang disiapkan.</p><a href="/admin/">Kembali ke panel</a></main></body></html>
