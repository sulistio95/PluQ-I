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
if ($method==='GET' && preg_match('#^/api/games/([a-zA-Z0-9_-]+)/fields$#D',$path,$m)===1) {
 try { require_once PLUQI_ROOT.'/config/database.php'; require_once PLUQI_ROOT.'/app/services/game_fields.php'; $schema=catalog_game_account_schema(database(),$m[1]); json_response(['data'=>$schema['fields'],'meta'=>['username_check_available'=>$schema['username_check_available'],'checkout_supported'=>$schema['checkout_supported']]]); }
 catch (Throwable $e) { json_response(['error'=>'game_fields_unavailable'],503); }
}
if ($method==='POST' && preg_match('#^/api/games/([a-zA-Z0-9_-]+)/check-username$#D',$path,$m)===1) {
 if (session_status() !== PHP_SESSION_ACTIVE) session_start();
 $payload = $_POST['payload'] ?? '';
 if (!is_string($payload) || strlen($payload) > 8000) json_response(['error'=>'invalid_payload'],413);
 $input = json_decode($payload, true, 16, JSON_BIGINT_AS_STRING);
 if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) json_response(['error'=>'invalid_payload'],400);
 try {
  require_once PLUQI_ROOT.'/config/database.php';
  require_once PLUQI_ROOT.'/app/services/game_fields.php';
  require_once PLUQI_ROOT.'/app/services/game_username_check.php';
  $pdo = database();
  $schema = catalog_game_account_schema($pdo, $m[1]);
  if (!$schema['checkout_supported']) json_response(['error'=>'account_schema_unverified','message'=>'Pemetaan akun game ini belum diverifikasi.'],409);
  if (!$schema['username_check_available']) json_response(['error'=>'username_check_unavailable','message'=>'Pengecekan username belum tersedia untuk game ini.'],409);
  $account = $input['account'] ?? null;
  if (!is_array($account) || array_is_list($account)) json_response(['error'=>'invalid_account'],422);
  $normalized = [];
  foreach ($schema['fields'] as $field) {
   $key = (string)$field['field_key'];
   $value = $account[$key] ?? '';
   if (!is_scalar($value)) json_response(['error'=>'invalid_account'],422);
   $value = trim((string)$value);
   if ($field['is_required'] && $value === '') json_response(['error'=>'missing_account_field','message'=>'Lengkapi semua kolom akun terlebih dahulu.'],422);
   if (strlen($value) > 190 || ($value !== '' && preg_match('~^(?:'.$field['validation_regex'].')$~uD',$value) !== 1)) json_response(['error'=>'invalid_account_field','message'=>'Format data akun tidak valid.'],422);
   $normalized[$key] = $value;
  }
  foreach (array_keys($account) as $key) if (!is_string($key) || !array_key_exists($key,$normalized)) json_response(['error'=>'unknown_account_field'],422);
  $fingerprint = hash('sha256', $m[1].'|'.json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
  // Rate-limit even cache hits to protect the public endpoint and database.
  $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  game_username_check_enforce_client_limit($pdo, $clientIp);
  // A successful cached check is safe to reuse briefly and avoids duplicate provider traffic.
  $cachedUsername = game_username_check_cached($pdo, $fingerprint);
  if ($cachedUsername !== null) {
   json_response(['data'=>['username'=>$cachedUsername,'verified'=>true,'cached'=>true]]);
  }
  $serviceCode = game_account_username_check_code((string)$schema['game']['slug'], (string)$schema['game']['name']);
  $userId = (string)($normalized['user_id'] ?? '');
  $zoneId = (string)($normalized['zone_id'] ?? $normalized['server'] ?? '');
  if ($serviceCode === null) {
   json_response(['error'=>'username_check_unavailable','message'=>'Pengecekan username VIPayment tidak mendukung game ini.'],409);
  }
  // VIPayment Game Feature Get Nickname: use supplier credentials already configured in admin settings.
  game_username_check_enforce_provider_limits($pdo, $fingerprint);
  $username = game_username_check($serviceCode, $userId, $zoneId);
  game_username_check_cache_success($pdo, $fingerprint, (string)$schema['game']['slug'], $username);
  // Opportunistic cleanup is bounded so this stays suitable for shared hosting.
  $pdo->exec('DELETE FROM game_username_check_cache WHERE expires_at <= UTC_TIMESTAMP() LIMIT 100');
  $pdo->exec('DELETE FROM game_username_check_limits WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY) LIMIT 100');
  json_response(['data'=>['username'=>$username,'verified'=>true,'cached'=>false]]);
 } catch (GameUsernameInvalidException $e) {
  json_response(['error'=>'invalid_game_account','message'=>'ID atau server akun game tidak ditemukan.'],422);
 } catch (GameUsernameRateLimitException $e) {
  json_response(['error'=>'username_check_throttled','message'=>'Pengecekan terlalu sering. Tunggu sebentar lalu coba lagi.'],429);
 } catch (DomainException $e) {
  json_response(['error'=>'game_unavailable','message'=>'Game tidak tersedia.'],404);
 } catch (InvalidArgumentException $e) {
  json_response(['error'=>'invalid_account','message'=>'Data akun tidak valid.'],422);
 } catch (Throwable $e) {
  // Log provider/network failures for diagnosis without exposing credentials to customers.
  $diagnostic = preg_replace('/(key|sign|api[_ -]?key|token|secret)\\s*[:=]\\s*[^\\s,;]+/i', '$1=[redacted]', $e->getMessage());
  error_log('[PluQ-I username-check] game=' . preg_replace('/[^a-zA-Z0-9_-]/', '', $m[1]) . ' error=' . substr((string)$diagnostic, 0, 240));
  json_response(['error'=>'username_check_failed','message'=>'Pengecekan username sedang tidak tersedia. Anda dapat mencoba lagi nanti.'],503);
 }
}
if ($method==='GET' && preg_match('#^/api/games/([a-zA-Z0-9_-]+)/products$#D',$path,$m)===1) {
 try { require_once PLUQI_ROOT.'/config/database.php'; require_once PLUQI_ROOT.'/app/services/catalog.php'; json_response(['data'=>catalog_products(database(),$m[1])]); }
 catch (Throwable $e) { json_response(['error'=>'catalog_unavailable'],503); }
}
if ($path==='/' && $method==='GET') {
 header('Content-Type: text/html; charset=utf-8');
 $name=htmlspecialchars($config['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 $gridColumns=5;
 $mobileGridColumns=3;
 $legacyIconSize=100;
 $desktopIconSize=null;
 $mobileIconSize=null;
 $brandLogoUrl='';
 $homepageAnnouncements=[];
 try {
  require_once PLUQI_ROOT.'/config/database.php';
  $layoutStmt=database()->query("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('catalog_grid_columns_desktop','catalog_grid_columns_mobile','catalog_icon_size','catalog_icon_size_desktop','catalog_icon_size_mobile','brand_logo_url')");
  foreach ($layoutStmt->fetchAll(PDO::FETCH_ASSOC) as $layoutRow) {
   if ($layoutRow['setting_key']==='catalog_grid_columns_desktop' && in_array((int)$layoutRow['setting_value'],[3,4,5,6],true)) $gridColumns=(int)$layoutRow['setting_value'];
   if ($layoutRow['setting_key']==='catalog_grid_columns_mobile' && in_array((int)$layoutRow['setting_value'],[2,3,4],true)) $mobileGridColumns=(int)$layoutRow['setting_value'];
   if ($layoutRow['setting_key']==='catalog_icon_size' && in_array((int)$layoutRow['setting_value'],[64,68,72,76,80,84,88,92,96,100,104,108,112,116,120],true)) $legacyIconSize=(int)$layoutRow['setting_value'];
   if ($layoutRow['setting_key']==='catalog_icon_size_desktop' && in_array((int)$layoutRow['setting_value'],[64,68,72,76,80,84,88,92,96,100,104,108,112,116,120],true)) $desktopIconSize=(int)$layoutRow['setting_value'];
   if ($layoutRow['setting_key']==='catalog_icon_size_mobile' && in_array((int)$layoutRow['setting_value'],[64,68,72,76,80,84,88,92,96,100,104,108,112,116,120],true)) $mobileIconSize=(int)$layoutRow['setting_value'];
   if ($layoutRow['setting_key']==='brand_logo_url') $brandLogoUrl=trim((string)$layoutRow['setting_value']);
  }
  $announcementStmt=database()->prepare("SELECT setting_value FROM settings WHERE setting_key='homepage_announcements' LIMIT 1");
  $announcementStmt->execute();
  $announcementJson=$announcementStmt->fetchColumn();
  $announcementData=is_string($announcementJson) ? json_decode($announcementJson,true) : null;
  if (is_array($announcementData)) $homepageAnnouncements=array_values(array_filter($announcementData,static fn($item)=>is_array($item) && !empty($item['active']) && isset($item['message']) && is_string($item['message'])));
  if ($desktopIconSize===null) $desktopIconSize=$legacyIconSize;
  if ($mobileIconSize===null) $mobileIconSize=$legacyIconSize;
 } catch (Throwable $e) {
  $desktopIconSize=$desktopIconSize ?? 100;
  $mobileIconSize=$mobileIconSize ?? 100;
  // Keep the storefront usable with safe defaults if settings cannot be read.
 }
 $brandMarkup = $brandLogoUrl !== ''
  ? '<img class="brand-logo" src="'.htmlspecialchars($brandLogoUrl,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'" alt="'.$name.'">'
  : 'PluQ-i';
 $announcementMarkup='';
 if ($homepageAnnouncements) {
  $announcementItems='';
  foreach ($homepageAnnouncements as $announcement) {
   $type=in_array(($announcement['type'] ?? 'info'),['info','maintenance','warning','promo'],true) ? $announcement['type'] : 'info';
   $announcementItems.='<span class="announcement-item announcement-'.$type.'">'.htmlspecialchars((string)$announcement['message'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</span>';
  }
  $announcementMarkup='<section class="announcement-bar" aria-label="Pengumuman toko"><div class="announcement-track">'.$announcementItems.$announcementItems.'</div></section>';
 }
 echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$name.'</title><link rel="stylesheet" href="/assets/catalog.css"><style>.announcement-bar{overflow:hidden;background:#14231e;border-bottom:1px solid #285344;color:#e5fff2;padding:10px 0;white-space:nowrap}.announcement-track{display:flex;width:max-content;min-width:200%;animation:announcement-scroll 28s linear infinite}.announcement-item{display:inline-block;padding:0 42px;font-size:13px}.announcement-maintenance{color:#fde68a}.announcement-warning{color:#fecaca}.announcement-promo{color:#a7f3d0}@keyframes announcement-scroll{from{transform:translateX(0)}to{transform:translateX(-50%)}}@media(prefers-reduced-motion:reduce){.announcement-track{animation:none;min-width:100%;justify-content:center;flex-wrap:wrap;white-space:normal}}:root{--catalog-grid-columns:'.$gridColumns.';--catalog-grid-columns-mobile:'.$mobileGridColumns.';--catalog-icon-size-desktop:'.$desktopIconSize.'px;--catalog-icon-size-mobile:'.$mobileIconSize.'px}</style></head><body><header><div class="top"><a class="brand" href="/">'.$brandMarkup.'</a></div></header>'.$announcementMarkup.'<main><section class="hero" aria-label="Banner promosi"><img id="banner-image" class="banner-img" alt=""></section><section id="hero-copy" class="hero-copy"><h1>Top up game lebih praktis</h1><p>Pilih game dan temukan nominal yang tersedia.</p></section><div id="search-row" class="search-row"><input id="search" class="search" type="search" placeholder="Cari game, misalnya Mobile Legend..." aria-label="Cari game"></div><section id="catalog-view"><div class="toolbar"><h2>Daftar Game</h2><span id="game-count" class="muted"></span></div><div id="games" class="game-grid"><div class="notice">Memuat katalog...</div></div></section><section id="product-view" class="hidden"><div class="toolbar"><h2 id="selected-game">Produk</h2><button id="back" type="button">Kembali ke game</button></div><section class="account-panel"><h3>Data akun game</h3><form id="account-form"><div id="game-fields" class="field-grid"><p class="muted">Memuat formulir otomatis...</p></div><div id="username-check" class="field username-check" role="status" aria-live="polite"><div id="username-result"></div></div><p id="field-message" class="notice hidden" role="status"></p></form></section><h3>Pilih nominal</h3><div id="products" class="products"></div><div id="customer-email-wrap" class="field hidden"><label for="customer-email">Email untuk pembayaran *</label><input id="customer-email" name="customer_email" type="email" maxlength="255" autocomplete="email" required placeholder="nama@contoh.com"></div><p id="checkout-note" class="muted">Pilih game dan isi data akun. Pembelian dan pembayaran online belum diaktifkan.</p></section></main><footer>PluQ-i Official Store</footer><script src="/assets/catalog.js" defer></script></body></html>';
 exit;
}
json_response(['error'=>'not_found'],404);
