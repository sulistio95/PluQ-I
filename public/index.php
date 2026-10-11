<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($path === '/api/health' && $method === 'GET') json_response(['status'=>'ok','app'=>$config['name'],'mode'=>'shared-hosting']);
if ($path === '/api/health/ready' && $method === 'GET') {
 try { require_once PLUQI_ROOT.'/config/database.php'; database()->query('SELECT 1'); json_response(['status'=>'ready','database'=>'ok']); }
 catch (Throwable $e) { json_response(['status'=>'not_ready','database'=>'unavailable'],503); }
}
if ($method === 'GET' && in_array($path,['/api/games','/api/banners'],true)) {
 try {
  require_once PLUQI_ROOT.'/config/database.php'; require_once PLUQI_ROOT.'/app/services/catalog.php'; $pdo=database();
  if ($path==='/api/games') json_response(['data'=>catalog_games($pdo)]);
  json_response(['data'=>catalog_banners($pdo)]);
 } catch (Throwable $e) { json_response(['error'=>'catalog_unavailable'],503); }
}
if ($method==='GET' && preg_match('#^/api/games/([a-zA-Z0-9_-]+)/products$#D',$path,$m)===1) {
 try { require_once PLUQI_ROOT.'/config/database.php'; require_once PLUQI_ROOT.'/app/services/catalog.php'; json_response(['data'=>catalog_products(database(),$m[1])]); }
 catch (Throwable $e) { json_response(['error'=>'catalog_unavailable'],503); }
}
if ($path==='/' && $method==='GET') {
 header('Content-Type: text/html; charset=utf-8');
 $name=htmlspecialchars($config['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$name.'</title><link rel="stylesheet" href="/assets/catalog.css"></head><body><header><div class="top"><a class="brand" href="/">PluQ</a><input id="search" class="search" type="search" placeholder="Cari game, misalnya Mobile Legend..." aria-label="Cari game"></div></header><main><section class="hero"><img id="banner-image" class="banner-img" alt=""><div class="hero-copy"><h1>Top up game lebih praktis</h1><p>Pilih game dan temukan nominal yang tersedia.</p></div></section><section id="catalog-view"><div class="toolbar"><h2>Daftar Game</h2><span id="game-count" class="muted"></span></div><div id="games" class="game-grid"><div class="notice">Memuat katalog...</div></div></section><section id="product-view" class="hidden"><div class="toolbar"><h2 id="selected-game">Produk</h2><button id="back" type="button">Kembali ke game</button></div><div id="products" class="products"></div><p class="muted">Harga mengikuti katalog terakhir. Pembelian online belum diaktifkan.</p></section></main><footer>PluQ · Katalog shared-hosting</footer><script src="/assets/catalog.js" defer></script></body></html>';
 exit;
}
json_response(['error'=>'not_found'],404);
