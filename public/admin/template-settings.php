<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
require_once PLUQI_ROOT . '/app/services/admin_auth.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');

admin_session_start();
$pdo = database();
$user = admin_current_user($pdo);
if (!$user) {
    header('Location: /admin/');
    exit;
}
$canManage = admin_can_manage_settings($user);
$error = '';
$notice = '';
$editing = null;

function banner_setting_url(string $url, bool $allowRelative = false): bool
{
    if ($allowRelative && str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return !str_contains($url, "\n") && !str_contains($url, "\r");
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
}

function banner_local_upload_path(string $url): ?string
{
    if (!preg_match('~^/uploads/banners/([a-f0-9]{32})\\.(jpg|png|webp)$~D', $url, $matches)) {
        return null;
    }
    return PLUQI_ROOT . '/public/uploads/banners/' . $matches[1] . '.' . $matches[2];
}

function banner_store_upload(array $file): string
{
    if (!isset($file['error'], $file['tmp_name'], $file['size'])
        || is_array($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Unggahan gambar gagal. Pilih gambar kembali.');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024
        || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new InvalidArgumentException('Ukuran gambar maksimal 5 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        throw new InvalidArgumentException('Format gambar harus JPG, PNG, atau WebP. SVG dan file lain tidak diizinkan.');
    }
    $imageInfo = @getimagesize((string) $file['tmp_name']);
    if (!is_array($imageInfo) || (int) $imageInfo[0] < 1 || (int) $imageInfo[1] < 1
        || (int) $imageInfo[0] > 8000 || (int) $imageInfo[1] > 8000
        || (int) $imageInfo[0] * (int) $imageInfo[1] > 25000000) {
        throw new InvalidArgumentException('Dimensi gambar tidak valid atau terlalu besar.');
    }
    $directory = PLUQI_ROOT . '/public/uploads/banners';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Direktori unggahan tidak dapat dibuat.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Server tidak dapat menyimpan gambar. Periksa izin folder uploads.');
    }
    return '/uploads/banners/' . $filename;
}

function banner_remove_local_file(string $url): bool
{
    $path = banner_local_upload_path($url);
    if ($path === null || !is_file($path)) {
        return true;
    }
    return @unlink($path);
}

function game_icon_local_path(string $url): ?string
{
    if (!preg_match('~^/uploads/game-icons/([a-f0-9]{32})\\.(jpg|png|webp)$~D', $url, $matches)) {
        return null;
    }
    return PLUQI_ROOT . '/public/uploads/game-icons/' . $matches[1] . '.' . $matches[2];
}

function game_icon_store_upload(array $file): string
{
    if (!isset($file['error'], $file['tmp_name'], $file['size'])
        || is_array($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Unggahan icon gagal. Pilih gambar kembali.');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024
        || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new InvalidArgumentException('Ukuran icon maksimal 5 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        throw new InvalidArgumentException('Format icon harus JPG, PNG, atau WebP. SVG dan format lain tidak diizinkan.');
    }
    $imageInfo = @getimagesize((string) $file['tmp_name']);
    if (!is_array($imageInfo) || (int) $imageInfo[0] < 1 || (int) $imageInfo[1] < 1
        || (int) $imageInfo[0] > 4000 || (int) $imageInfo[1] > 4000
        || (int) $imageInfo[0] * (int) $imageInfo[1] > 10000000) {
        throw new InvalidArgumentException('Dimensi icon tidak valid atau terlalu besar (maks. 4000×4000 dan 10 megapiksel).');
    }
    $directory = PLUQI_ROOT . '/public/uploads/game-icons';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Direktori icon tidak dapat dibuat.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Server tidak dapat menyimpan icon. Periksa izin folder uploads.');
    }
    return '/uploads/game-icons/' . $filename;
}

function game_icon_remove_local_file(string $url): bool
{
    $path = game_icon_local_path($url);
    return $path === null || !is_file($path) || @unlink($path);
}

function brand_logo_local_path(string $url): ?string
{
    if (!preg_match('~^/uploads/brand-logos/([a-f0-9]{32})\\.(jpg|png|webp)$~D', $url, $matches)) {
        return null;
    }
    return PLUQI_ROOT . '/public/uploads/brand-logos/' . $matches[1] . '.' . $matches[2];
}

function brand_logo_store_upload(array $file): string
{
    if (!isset($file['error'], $file['tmp_name'], $file['size'])
        || is_array($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Unggahan logo gagal. Pilih gambar kembali.');
    }
    if ((int) $file['size'] < 1 || (int) $file['size'] > 5 * 1024 * 1024
        || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new InvalidArgumentException('Ukuran logo maksimal 5 MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!is_string($mime) || !isset($extensions[$mime])) {
        throw new InvalidArgumentException('Format logo harus JPG, PNG, atau WebP. SVG dan format lain tidak diizinkan.');
    }
    $imageInfo = @getimagesize((string) $file['tmp_name']);
    if (!is_array($imageInfo) || (int) $imageInfo[0] < 1 || (int) $imageInfo[1] < 1
        || (int) $imageInfo[0] > 8000 || (int) $imageInfo[1] > 8000
        || (int) $imageInfo[0] * (int) $imageInfo[1] > 25000000) {
        throw new InvalidArgumentException('Dimensi logo tidak valid atau terlalu besar.');
    }
    $directory = PLUQI_ROOT . '/public/uploads/brand-logos';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Direktori logo tidak dapat dibuat.');
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file((string) $file['tmp_name'], $directory . '/' . $filename)) {
        throw new RuntimeException('Server tidak dapat menyimpan logo. Periksa izin folder uploads.');
    }
    return '/uploads/brand-logos/' . $filename;
}

function brand_logo_remove_local_file(string $url): bool
{
    $path = brand_logo_local_path($url);
    return $path === null || !is_file($path) || @unlink($path);
}

/**
 * Commit an uploaded game icon or brand logo to the configured GitHub repository.
 * Credentials must come from the server environment/private .env, never from request data.
 */
function game_icon_commit_to_github(string $localPath, string $publicUrl): void
{
    $token = trim((string) (getenv('GITHUB_UPLOAD_TOKEN') ?: ''));
    $repository = trim((string) (getenv('GITHUB_UPLOAD_REPOSITORY') ?: ''));
    $branch = trim((string) (getenv('GITHUB_UPLOAD_BRANCH') ?: ''));

    if ($token === '' || $repository === '' || $branch === '') {
        throw new RuntimeException('Sinkronisasi GitHub belum dikonfigurasi. Atur GITHUB_UPLOAD_TOKEN, GITHUB_UPLOAD_REPOSITORY, dan GITHUB_UPLOAD_BRANCH pada konfigurasi privat server.');
    }
    if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repository)
        || $branch === '' || str_contains($branch, '..')
        || !preg_match('~^[A-Za-z0-9._/-]+$~D', $branch)) {
        throw new RuntimeException('Konfigurasi repositori atau branch GitHub tidak valid.');
    }
    $path = parse_url($publicUrl, PHP_URL_PATH);
    if (!is_string($path) || !preg_match('~^/uploads/(?:game-icons|brand-logos)/([a-f0-9]{32}\\.(?:jpg|png|webp))$~D', $path, $matches)) {
        throw new RuntimeException('Path gambar tidak valid untuk sinkronisasi GitHub.');
    }
    $repoPath = 'public' . $path;
    $isBrandLogo = str_starts_with($path, '/uploads/brand-logos/');
    $bytes = @file_get_contents($localPath);
    if (!is_string($bytes) || $bytes === '') {
        throw new RuntimeException('File gambar lokal tidak dapat dibaca untuk dikirim ke GitHub.');
    }

    $payload = json_encode([
        'message' => 'Add ' . ($isBrandLogo ? 'brand logo ' : 'game icon ') . $matches[1],
        'content' => base64_encode($bytes),
        'branch' => $branch,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $url = 'https://api.github.com/repos/' . $repository . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $repoPath)));
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Tidak dapat memulai koneksi GitHub API.');
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: PluQ-I-Admin',
        ],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if (!is_string($response) || $status < 200 || $status >= 300) {
        // Do not include the authorization token or raw response body in the error.
        throw new RuntimeException(
            'GitHub gagal menyimpan icon (HTTP ' . $status . '). '
            . ($curlError !== '' ? 'Periksa koneksi HTTPS server. ' : '')
            . 'Periksa izin Contents: Read and write, nama repositori, dan branch.'
        );
    }
}


/**
 * Delete an obsolete brand logo from the configured GitHub branch.
 * Only the strict brand-logo upload path is accepted; game icons are never touched.
 * A 404 on the initial lookup means the file is already absent and is safe to ignore.
 */
function brand_logo_delete_from_github(string $publicUrl): void
{
    $token = trim((string) (getenv('GITHUB_UPLOAD_TOKEN') ?: ''));
    $repository = trim((string) (getenv('GITHUB_UPLOAD_REPOSITORY') ?: ''));
    $branch = trim((string) (getenv('GITHUB_UPLOAD_BRANCH') ?: ''));

    if ($token === '' || $repository === '' || $branch === '') {
        throw new RuntimeException('Penghapusan logo GitHub belum dikonfigurasi.');
    }
    if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repository)
        || str_contains($branch, '..')
        || !preg_match('~^[A-Za-z0-9._/-]+$~D', $branch)) {
        throw new RuntimeException('Konfigurasi repositori atau branch GitHub tidak valid.');
    }
    $path = parse_url($publicUrl, PHP_URL_PATH);
    if (!is_string($path) || !preg_match('~^/uploads/brand-logos/([a-f0-9]{32}\.(?:jpg|png|webp))$~D', $path, $matches)) {
        // External URLs and unrelated paths must never trigger repository deletion.
        return;
    }

    $repoPath = 'public' . $path;
    $url = 'https://api.github.com/repos/' . $repository . '/contents/' . implode('/', array_map('rawurlencode', explode('/', $repoPath)));
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: PluQ-I-Admin',
    ];

    $curl = curl_init($url . '?ref=' . rawurlencode($branch));
    if ($curl === false) {
        throw new RuntimeException('Tidak dapat memulai koneksi GitHub API.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($status === 404) {
        return;
    }
    if (!is_string($response) || $status < 200 || $status >= 300) {
        throw new RuntimeException('GitHub gagal memeriksa logo lama (HTTP ' . $status . ').' . ($curlError !== '' ? ' Periksa koneksi HTTPS server.' : ''));
    }
    $file = json_decode($response, true);
    $fileSha = is_array($file) ? (string) ($file['sha'] ?? '') : '';
    if (!preg_match('~^[a-f0-9]{40}$~D', $fileSha)) {
        throw new RuntimeException('GitHub tidak mengembalikan SHA file logo yang valid.');
    }

    $payload = json_encode([
        'message' => 'Remove obsolete brand logo ' . $matches[1],
        'sha' => $fileSha,
        'branch' => $branch,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Tidak dapat memulai koneksi penghapusan GitHub.');
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge($headers, ['Content-Type: application/json']),
    ]);
    $deleteResponse = curl_exec($curl);
    $deleteStatus = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $deleteError = curl_error($curl);
    curl_close($curl);

    if (!is_string($deleteResponse) || $deleteStatus < 200 || $deleteStatus >= 300) {
        throw new RuntimeException('GitHub gagal menghapus logo lama (HTTP ' . $deleteStatus . ').' . ($deleteError !== '' ? ' Periksa koneksi HTTPS server.' : ' Periksa izin Contents: Read and write dan branch.'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $error = 'Sesi formulir tidak valid. Muat ulang halaman dan coba lagi.';
    } elseif (!$canManage) {
        http_response_code(403);
        $error = 'Peran akun Anda tidak diizinkan mengubah pengaturan template.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'save') {
                $id = filter_var($_POST['id'] ?? '0', FILTER_VALIDATE_INT);
                $title = trim((string) ($_POST['title'] ?? ''));
                $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
                $targetUrl = trim((string) ($_POST['target_url'] ?? ''));
                $sortOrder = filter_var($_POST['sort_order'] ?? '0', FILTER_VALIDATE_INT);
                $status = (string) ($_POST['status'] ?? 'ACTIVE');
                if ($id === false || $id < 0 || $title === '' || strlen($title) > 160
                    || strlen($imageUrl) > 2000 || ($imageUrl !== '' && !banner_setting_url($imageUrl))
                    || strlen($targetUrl) > 2000 || ($targetUrl !== '' && !banner_setting_url($targetUrl, true))
                    || $sortOrder === false || $sortOrder < 0 || !in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
                    throw new InvalidArgumentException('Periksa judul, URL gambar HTTPS, tautan tujuan, urutan, dan status banner.');
                }

                $existing = null;
                if ($id > 0) {
                    $findExisting = $pdo->prepare('SELECT id,image_url FROM banners WHERE id=:id LIMIT 1');
                    $findExisting->execute(['id'=>$id]);
                    $existing = $findExisting->fetch(PDO::FETCH_ASSOC) ?: null;
                    if (!$existing) throw new InvalidArgumentException('Banner yang diedit tidak ditemukan.');
                }
                $uploadError = (int) ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE);
                $hasUpload = $uploadError !== UPLOAD_ERR_NO_FILE;
                $newUploadUrl = null;
                if ($hasUpload) {
                    $newUploadUrl = banner_store_upload($_FILES['image_file']);
                    $imageUrl = $newUploadUrl;
                } elseif ($imageUrl === '' && $existing) {
                    $imageUrl = (string) $existing['image_url'];
                } elseif ($imageUrl === '' && !$existing) {
                    throw new InvalidArgumentException('Pilih gambar dari laptop atau masukkan URL gambar HTTPS.');
                }

                try {
                    if ($id > 0) {
                        $stmt = $pdo->prepare('UPDATE banners SET title=:title,image_url=:image,target_url=:target,sort_order=:sort,status=:status WHERE id=:id');
                        $stmt->execute(['title'=>$title,'image'=>$imageUrl,'target'=>$targetUrl !== '' ? $targetUrl : null,'sort'=>$sortOrder,'status'=>$status,'id'=>$id]);
                        $entityId = (string) $id;
                        $auditAction = 'BANNER_UPDATED';
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO banners (title,image_url,target_url,sort_order,status) VALUES (:title,:image,:target,:sort,:status)');
                        $stmt->execute(['title'=>$title,'image'=>$imageUrl,'target'=>$targetUrl !== '' ? $targetUrl : null,'sort'=>$sortOrder,'status'=>$status]);
                        $entityId = (string) $pdo->lastInsertId();
                        $auditAction = 'BANNER_CREATED';
                    }
                    $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,:action,'BANNER',:entity,:details,:ip)");
                    $audit->execute([
                        'actor'=>$user['id'], 'action'=>$auditAction, 'entity'=>$entityId,
                        'details'=>json_encode(['title'=>$title,'status'=>$status,'sort_order'=>$sortOrder,'image_source'=>str_starts_with($imageUrl, '/uploads/banners/') ? 'LOCAL_UPLOAD' : 'HTTPS_URL'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                        'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),
                    ]);
                } catch (Throwable $saveError) {
                    if ($newUploadUrl !== null) banner_remove_local_file($newUploadUrl);
                    throw $saveError;
                }
                if ($existing && (string) $existing['image_url'] !== $imageUrl) {
                    banner_remove_local_file((string) $existing['image_url']);
                }
                header('Location: /admin/template-settings.php?saved=1');
                exit;
            }
            if ($action === 'deactivate') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) throw new InvalidArgumentException('ID banner tidak valid.');
                $stmt = $pdo->prepare("UPDATE banners SET status='INACTIVE' WHERE id=:id");
                $stmt->execute(['id'=>$id]);
                $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,ip_address) VALUES ('ADMIN',:actor,'BANNER_DEACTIVATED','BANNER',:entity,:ip)");
                $audit->execute(['actor'=>$user['id'],'entity'=>(string)$id,'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45)]);
                header('Location: /admin/template-settings.php?deactivated=1');
                exit;
            }
            if ($action === 'delete') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) throw new InvalidArgumentException('ID banner tidak valid.');
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT id,title,image_url FROM banners WHERE id=:id FOR UPDATE');
                $stmt->execute(['id'=>$id]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) throw new InvalidArgumentException('Banner tidak ditemukan.');
                $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,'BANNER_DELETED','BANNER',:entity,:details,:ip)");
                $audit->execute([
                    'actor'=>$user['id'], 'entity'=>(string)$id,
                    'details'=>json_encode(['title'=>$row['title'],'local_image'=>banner_local_upload_path((string)$row['image_url']) !== null], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                    'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),
                ]);
                $remove = $pdo->prepare('DELETE FROM banners WHERE id=:id');
                $remove->execute(['id'=>$id]);
                $pdo->commit();
                if (!banner_remove_local_file((string)$row['image_url'])) {
                    header('Location: /admin/template-settings.php?deleted=1&file_cleanup=failed');
                } else {
                    header('Location: /admin/template-settings.php?deleted=1');
                }
                exit;
            }

            if ($action === 'save_brand_logo') {
                $uploadError = (int) ($_FILES['brand_logo_file']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($uploadError === UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('Pilih file logo dari komputer terlebih dahulu.');
                $oldStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='brand_logo_url' LIMIT 1");
                $oldStmt->execute();
                $oldLogoUrl = (string) ($oldStmt->fetchColumn() ?: '');
                $newLogoUrl = brand_logo_store_upload($_FILES['brand_logo_file']);
                try {
                    $newLogoPath = brand_logo_local_path($newLogoUrl);
                    if ($newLogoPath === null) throw new RuntimeException('Path logo lokal tidak valid.');
                    game_icon_commit_to_github($newLogoPath, $newLogoUrl);
                    $pdo->beginTransaction();
                    $saveLogo = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by) VALUES ('brand_logo_url',:url,:admin) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)");
                    $saveLogo->execute(['url'=>$newLogoUrl,'admin'=>$user['id']]);
                    $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,'BRAND_LOGO_UPDATED','APP_SETTING','brand_logo_url',:details,:ip)");
                    $audit->execute([
                        'actor'=>$user['id'],
                        'details'=>json_encode(['image_source'=>'LOCAL_UPLOAD'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                        'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),
                    ]);
                    $pdo->commit();
                } catch (Throwable $saveError) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    brand_logo_remove_local_file($newLogoUrl);
                    throw $saveError;
                }
                $oldLogoCleanupFailed = false;
                if ($oldLogoUrl !== '' && $oldLogoUrl !== $newLogoUrl) {
                    try {
                        // Re-read the active setting before deleting the old remote asset.
                        $activeLogo = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='brand_logo_url' LIMIT 1")->fetchColumn();
                        if ((string) $activeLogo !== $oldLogoUrl) {
                            brand_logo_delete_from_github($oldLogoUrl);
                            brand_logo_remove_local_file($oldLogoUrl);
                        }
                    } catch (Throwable $cleanupError) {
                        error_log('PluQ-I brand logo cleanup failed: ' . $cleanupError->getMessage());
                        $oldLogoCleanupFailed = true;
                    }
                }
                header('Location: /admin/template-settings.php?brand_logo_saved=1' . ($oldLogoCleanupFailed ? '&old_logo_cleanup=failed' : ''));
                exit;
            }
            if ($action === 'delete_brand_logo') {
                $oldStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='brand_logo_url' LIMIT 1");
                $oldStmt->execute();
                $oldLogoUrl = (string) ($oldStmt->fetchColumn() ?: '');
                if ($oldLogoUrl === '') throw new InvalidArgumentException('Logo belum diatur.');
                $pdo->beginTransaction();
                $saveLogo = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by) VALUES ('brand_logo_url','',:admin) ON DUPLICATE KEY UPDATE setting_value='',updated_by=VALUES(updated_by)");
                $saveLogo->execute(['admin'=>$user['id']]);
                $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,'BRAND_LOGO_REMOVED','APP_SETTING','brand_logo_url',:details,:ip)");
                $audit->execute([
                    'actor'=>$user['id'],
                    'details'=>json_encode(['previous_logo_present'=>true], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                    'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),
                ]);
                $pdo->commit();
                $oldLogoCleanupFailed = false;
                try {
                    // Delete only after the setting is cleared and verify it remains unused.
                    $activeLogo = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='brand_logo_url' LIMIT 1")->fetchColumn();
                    if ((string) $activeLogo !== $oldLogoUrl) {
                        brand_logo_delete_from_github($oldLogoUrl);
                        brand_logo_remove_local_file($oldLogoUrl);
                    }
                } catch (Throwable $cleanupError) {
                    error_log('PluQ-I brand logo cleanup failed: ' . $cleanupError->getMessage());
                    $oldLogoCleanupFailed = true;
                }
                header('Location: /admin/template-settings.php?brand_logo_deleted=1' . ($oldLogoCleanupFailed ? '&old_logo_cleanup=failed' : ''));
                exit;
            }
            if ($action === 'save_game_icon') {
                $gameId = filter_var($_POST['game_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$gameId || $gameId < 1) throw new InvalidArgumentException('Game yang dipilih tidak valid.');
                $findGame = $pdo->prepare("SELECT id,name,icon_url FROM games WHERE id=:id AND status='ACTIVE' LIMIT 1");
                $findGame->execute(['id'=>$gameId]);
                $game = $findGame->fetch(PDO::FETCH_ASSOC);
                if (!$game) throw new InvalidArgumentException('Game tidak ditemukan atau belum dicentang/diaktifkan pada Game List.');
                $uploadError = (int) ($_FILES['game_icon_file']['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($uploadError === UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('Pilih file icon dari laptop terlebih dahulu.');
                $newIconUrl = game_icon_store_upload($_FILES['game_icon_file']);
                try {
                    $newIconPath = game_icon_local_path($newIconUrl);
                    if ($newIconPath === null) {
                        throw new RuntimeException('Path icon lokal tidak valid.');
                    }
                    game_icon_commit_to_github($newIconPath, $newIconUrl);
                    $pdo->beginTransaction();
                    $update = $pdo->prepare('UPDATE games SET icon_url=:icon WHERE id=:id AND status=\'ACTIVE\'');
                    $update->execute(['icon'=>$newIconUrl,'id'=>$gameId]);
                    $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,'GAME_ICON_UPDATED','GAME',:entity,:details,:ip)");
                    $audit->execute([
                        'actor'=>$user['id'], 'entity'=>(string)$gameId,
                        'details'=>json_encode(['game_name'=>$game['name'],'image_source'=>'LOCAL_UPLOAD'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                        'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),
                    ]);
                    $pdo->commit();
                } catch (Throwable $saveError) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    game_icon_remove_local_file($newIconUrl);
                    throw $saveError;
                }
                game_icon_remove_local_file((string)($game['icon_url'] ?? ''));
                header('Location: /admin/template-settings.php?icon_saved=1');
                exit;
            }
            if ($action === 'delete_game_icon') {
                $gameId = filter_var($_POST['game_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$gameId || $gameId < 1) throw new InvalidArgumentException('Game yang dipilih tidak valid.');
                $pdo->beginTransaction();
                $findGame = $pdo->prepare("SELECT id,name,icon_url FROM games WHERE id=:id AND status='ACTIVE' FOR UPDATE");
                $findGame->execute(['id'=>$gameId]);
                $game = $findGame->fetch(PDO::FETCH_ASSOC);
                if (!$game) throw new InvalidArgumentException('Game tidak ditemukan atau belum dicentang/diaktifkan pada Game List.');
                if (trim((string)($game['icon_url'] ?? '')) === '') throw new InvalidArgumentException('Game ini belum memiliki icon untuk dihapus.');
                $update = $pdo->prepare('UPDATE games SET icon_url=NULL WHERE id=:id');
                $update->execute(['id'=>$gameId]);
                $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,'GAME_ICON_DELETED','GAME',:entity,:details,:ip)");
                $audit->execute([
                    'actor'=>$user['id'], 'entity'=>(string)$gameId,
                    'details'=>json_encode(['game_name'=>$game['name'],'local_image'=>game_icon_local_path((string)$game['icon_url']) !== null], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                    'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),
                ]);
                $pdo->commit();
                if (!game_icon_remove_local_file((string)$game['icon_url'])) {
                    header('Location: /admin/template-settings.php?icon_deleted=1&file_cleanup=failed');
                } else {
                    header('Location: /admin/template-settings.php?icon_deleted=1');
                }
                exit;
            }
            if ($action === 'save_catalog_layout') {
                $columns = filter_var($_POST['grid_columns'] ?? null, FILTER_VALIDATE_INT);
                $mobileColumns = filter_var($_POST['mobile_grid_columns'] ?? null, FILTER_VALIDATE_INT);
                $iconSize = filter_var($_POST['icon_size'] ?? null, FILTER_VALIDATE_INT);
                if (!in_array($columns, [3, 4, 5, 6], true)
                    || !in_array($mobileColumns, [2, 3, 4], true)
                    || !in_array($iconSize, [80, 84, 88, 92, 96, 100, 104, 108, 112, 116, 120], true)) {
                    throw new InvalidArgumentException('Pilih jumlah kolom desktop 3–6, kolom ponsel 2–4, dan ukuran icon 80–120 piksel.');
                }
                $pdo->beginTransaction();
                $saveSetting = $pdo->prepare("INSERT INTO app_settings (setting_key,setting_value,updated_by) VALUES (:setting_key,:setting_value,:admin) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)");
                $saveSetting->execute(['setting_key'=>'catalog_grid_columns_desktop','setting_value'=>(string)$columns,'admin'=>$user['id']]);
                $saveSetting->execute(['setting_key'=>'catalog_grid_columns_mobile','setting_value'=>(string)$mobileColumns,'admin'=>$user['id']]);
                $saveSetting->execute(['setting_key'=>'catalog_icon_size','setting_value'=>(string)$iconSize,'admin'=>$user['id']]);
                $audit = $pdo->prepare("INSERT INTO audit_logs (actor_type,actor_id,action,entity_type,entity_id,details,ip_address) VALUES ('ADMIN',:actor,'CATALOG_LAYOUT_UPDATED','APP_SETTING','catalog_layout',:details,:ip)");
                $audit->execute([
                    'actor'=>$user['id'],
                    'details'=>json_encode(['desktop_columns'=>$columns,'mobile_columns'=>$mobileColumns,'icon_size_px'=>$iconSize], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                    'ip'=>substr((string)($_SERVER['REMOTE_ADDR'] ?? ''),0,45),
                ]);
                $pdo->commit();
                header('Location: /admin/template-settings.php?layout_saved=1');
                exit;
            }
            throw new InvalidArgumentException('Aksi tidak dikenal.');
        } catch (InvalidArgumentException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Pengaturan template gagal disimpan. Periksa log server dan pastikan database serta folder uploads dapat diakses.';
        }
    }
}
if (($_GET['saved'] ?? '') === '1') $notice = 'Banner berhasil disimpan.';
if (($_GET['deactivated'] ?? '') === '1') $notice = 'Banner dinonaktifkan dan tidak akan ditampilkan di halaman depan.';
if (($_GET['deleted'] ?? '') === '1') $notice = (($_GET['file_cleanup'] ?? '') === 'failed') ? 'Banner dihapus dari database, tetapi file gambar lokal gagal dihapus. Periksa folder public/uploads/banners.' : 'Banner dihapus. Jika menggunakan gambar lokal, file gambarnya juga dihapus.';
if (($_GET['brand_logo_saved'] ?? '') === '1') $notice = 'Logo header berhasil disimpan ke GitHub dan diterapkan pada halaman depan.';
if (($_GET['brand_logo_deleted'] ?? '') === '1') $notice = 'Logo header dihapus. Tulisan PluQ-i ditampilkan kembali.';
if (($_GET['old_logo_cleanup'] ?? '') === 'failed') $notice = 'Logo baru berhasil diterapkan, tetapi file logo lama gagal dibersihkan dari GitHub. Periksa log PHP server dan konfigurasi token/branch GitHub.';
if (($_GET['icon_saved'] ?? '') === '1') $notice = 'Icon game berhasil disimpan ke GitHub dan ditampilkan pada katalog.';
if (($_GET['icon_deleted'] ?? '') === '1') $notice = (($_GET['file_cleanup'] ?? '') === 'failed') ? 'Icon dihapus dari katalog, tetapi file lokal gagal dihapus. Periksa folder public/uploads/game-icons.' : 'Icon game dihapus. Game tetap tampil di katalog tanpa icon khusus.';
if (($_GET['layout_saved'] ?? '') === '1') $notice = 'Tata letak katalog berhasil disimpan.';

if (isset($_GET['edit'])) {
    $id = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($id && $id > 0) {
        $stmt = $pdo->prepare('SELECT id,title,image_url,target_url,sort_order,status FROM banners WHERE id=:id LIMIT 1');
        $stmt->execute(['id'=>$id]);
        $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
$banners = $pdo->query('SELECT id,title,image_url,target_url,sort_order,status FROM banners ORDER BY sort_order,id DESC')->fetchAll(PDO::FETCH_ASSOC);
$activeGames = $pdo->query("SELECT id,slug,name,icon_url FROM games WHERE status='ACTIVE' ORDER BY sort_order,name")->fetchAll(PDO::FETCH_ASSOC);
$brandLogoStmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key='brand_logo_url' LIMIT 1");
$brandLogoStmt->execute();
$brandLogoUrl = trim((string) ($brandLogoStmt->fetchColumn() ?: ''));

$layoutSettings = ['catalog_grid_columns_desktop' => '5', 'catalog_grid_columns_mobile' => '3', 'catalog_icon_size' => '100'];
$layoutQuery = $pdo->prepare("SELECT setting_key,setting_value FROM app_settings WHERE setting_key IN ('catalog_grid_columns_desktop','catalog_grid_columns_mobile','catalog_icon_size')");
$layoutQuery->execute();
foreach ($layoutQuery->fetchAll(PDO::FETCH_ASSOC) as $layoutRow) {
    if (isset($layoutSettings[$layoutRow['setting_key']])) $layoutSettings[$layoutRow['setting_key']] = (string)$layoutRow['setting_value'];
}
$gridColumns = in_array((int)$layoutSettings['catalog_grid_columns_desktop'], [3,4,5,6], true) ? (int)$layoutSettings['catalog_grid_columns_desktop'] : 5;
$mobileGridColumns = in_array((int)$layoutSettings['catalog_grid_columns_mobile'], [2,3,4], true) ? (int)$layoutSettings['catalog_grid_columns_mobile'] : 3;
$iconSize = in_array((int)$layoutSettings['catalog_icon_size'], [80,84,88,92,96,100,104,108,112,116,120], true) ? (int)$layoutSettings['catalog_icon_size'] : 100;

$csrf = admin_csrf_token();
?>
<!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pengaturan Template · PluQ-I</title>
<style>
.brand-logo-preview{display:block;max-width:min(100%,360px);max-height:100px;object-fit:contain;background:#090909;border:1px solid var(--line);border-radius:8px;padding:8px}
:root{color-scheme:dark;--bg:#0c0d10;--panel:#15171d;--line:#292d36;--text:#f3f4f6;--muted:#9ca3af;--accent:#a7f3d0}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,sans-serif}main{max-width:1150px;margin:auto;padding:24px}h1,h2{margin:0 0 12px}.muted{color:var(--muted)}.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:16px 0}.bar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}label{display:grid;gap:6px;color:var(--muted)}input,select,button{width:100%;border:1px solid var(--line);background:#0d0f13;color:var(--text);padding:10px;border-radius:7px;font:inherit}button{cursor:pointer;background:#d1fae5;color:#111827;font-weight:700}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.actions button{width:auto}.notice,.error{padding:10px;border-radius:7px;margin:12px 0}.notice{background:#15352b}.error{background:#3b1d22}a{color:var(--accent)}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid var(--line);vertical-align:top}th{color:var(--muted)}.thumb{width:140px;max-height:70px;object-fit:cover;border-radius:6px;background:#08090b}.game-icon-thumb{width:72px;height:72px;object-fit:contain;border-radius:10px;background:#08090b;padding:4px}.icon-matrix{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px}.icon-card{min-width:0;border:1px solid var(--line);border-radius:10px;background:#101217;padding:12px;display:flex;flex-direction:column;gap:10px}.icon-card-head{display:flex;align-items:center;gap:10px;min-width:0}.icon-card-head>div{min-width:0;overflow-wrap:anywhere}.icon-card form{margin-top:auto}.icon-card .actions{margin-top:8px}.dimension-hint{display:block;font-size:12px;line-height:1.45;color:var(--muted)}.small{font-size:12px;overflow-wrap:anywhere;max-width:260px}.inline{display:inline}.inline button{padding:6px 9px;width:auto}@media(max-width:600px){main{padding:12px}.panel{padding:12px}}
</style>
<link rel="stylesheet" href="/assets/admin-sidebar.css"></head><body class="admin-layout">
<?php require PLUQI_ROOT . '/app/views/admin_sidebar.php'; ?><main>
<header class="bar"><div><h1>Pengaturan Template</h1><div class="muted">Kelola banner slider di halaman depan PluQ-I.</div></div></header>
<?php if ($error !== ''): ?><div class="error"><?=admin_escape($error)?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="notice"><?=admin_escape($notice)?></div><?php endif; ?>
<section class="panel"><h2><?= $editing ? 'Edit banner' : 'Tambah banner' ?></h2>
<p class="muted">Slider halaman depan berputar otomatis setiap 3 detik. Gunakan gambar yang Anda miliki atau berizin untuk digunakan. Pilih gambar dari laptop (JPG/PNG/WebP, maksimal 5 MB) atau masukkan URL HTTPS. Jika mengunggah gambar baru saat edit, gambar lokal lama akan dihapus setelah penggantian berhasil.</p><p class="dimension-hint"><strong>Saran ukuran banner: 1200 × 400 px (rasio 3:1).</strong> Gunakan gambar landscape; area penting sebaiknya tidak terlalu dekat tepi karena tampilan dapat menyesuaikan ukuran layar. Ukuran ini adalah rekomendasi, bukan batas wajib.</p>
<?php if (!$canManage): ?><p class="muted">Akun ini hanya dapat melihat pengaturan. ADMIN atau SUPER_ADMIN diperlukan untuk mengubah banner.</p><?php else: ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=admin_escape($editing['id'] ?? 0)?>">
<div class="grid">
<label>Nama/judul banner<input name="title" maxlength="160" required value="<?=admin_escape($editing['title'] ?? '')?>" placeholder="Contoh: Promo Mobile Legends"></label>
<label>Unggah gambar dari laptop (JPG/PNG/WebP, maks. 5 MB)<input type="file" name="image_file" accept="image/jpeg,image/png,image/webp"></label>
<label>Atau URL gambar (HTTPS)<input type="url" name="image_url" maxlength="2000" value="<?=admin_escape(isset($editing['image_url']) && !str_starts_with((string)$editing['image_url'], '/uploads/banners/') ? $editing['image_url'] : '')?>" placeholder="https://.../banner.webp"><span class="muted">Kosongkan saat edit untuk mempertahankan gambar saat ini, atau unggah gambar baru.</span></label>
<label>Tautan tujuan saat diklik (opsional)<input type="text" name="target_url" maxlength="2000" value="<?=admin_escape($editing['target_url'] ?? '')?>" placeholder="/game/mobile-legends atau https://..."></label>
<label>Urutan tampilan<input type="number" name="sort_order" min="0" max="1000000" required value="<?=admin_escape($editing['sort_order'] ?? 0)?>"></label>
<label>Status<select name="status"><option value="ACTIVE" <?=($editing['status'] ?? 'ACTIVE')==='ACTIVE'?'selected':''?>>Aktif</option><option value="INACTIVE" <?=($editing['status'] ?? '')==='INACTIVE'?'selected':''?>>Nonaktif</option></select></label>
</div><div class="actions"><button type="submit">Simpan banner</button><?php if ($editing): ?><a href="/admin/template-settings.php">Batal edit</a><?php endif; ?></div>
</form><?php endif; ?></section>
<section class="panel"><h2>Daftar banner</h2><p class="muted">Banner aktif akan dikirim melalui API katalog untuk slider halaman depan. Urutan lebih kecil tampil lebih dahulu.</p>
<div class="table-wrap"><table><thead><tr><th>Pratinjau</th><th>Judul dan tautan</th><th>Urutan</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
<?php if (!$banners): ?><tr><td colspan="5" class="muted">Belum ada banner tersimpan.</td></tr><?php endif; ?>
<?php foreach ($banners as $banner): ?><tr><td><img class="thumb" src="<?=admin_escape($banner['image_url'])?>" alt="<?=admin_escape($banner['title'])?>" loading="lazy"></td><td><?=admin_escape($banner['title'])?><div class="muted small"><?=admin_escape($banner['image_url'])?></div><div class="muted small">Tujuan: <?=admin_escape($banner['target_url'] ?: 'Tidak ada')?></div></td><td><?=number_format((int)$banner['sort_order'])?></td><td><?=admin_escape($banner['status'])?></td><td><div class="actions"><a href="/admin/template-settings.php?edit=<?=(int)$banner['id']?>">Edit</a><?php if ($canManage): ?><?php if ($banner['status']==='ACTIVE'): ?><form class="inline" method="post" onsubmit="return confirm('Nonaktifkan banner ini?')"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?=(int)$banner['id']?>"><button type="submit">Nonaktifkan</button></form><?php endif; ?><form class="inline" method="post" onsubmit="return confirm('Hapus banner ini secara permanen? File lokal yang terkait juga akan dihapus.')"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$banner['id']?>"><button type="submit">Hapus</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="panel"><h2>Logo Header Halaman Depan</h2>
<p class="muted">Ganti tulisan PluQ-i di pojok kiri atas halaman depan dengan gambar logo pilihanmu. Format JPG/PNG/WebP, maksimal 5 MB. Logo akan disimpan ke GitHub pada branch yang dikonfigurasi server agar dapat ikut saat deployment.</p>
<?php if ($brandLogoUrl !== ''): ?><p><img class="brand-logo-preview" src="<?=admin_escape($brandLogoUrl)?>" alt="Pratinjau logo header"></p><?php else: ?><p class="muted">Belum ada logo. Halaman depan masih menampilkan tulisan PluQ-i.</p><?php endif; ?>
<?php if ($canManage): ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="save_brand_logo"><label>File logo (JPG/PNG/WebP, maks. 5 MB)<input type="file" name="brand_logo_file" accept="image/jpeg,image/png,image/webp" required></label><div class="actions"><button type="submit">Unggah/Ganti logo</button></div></form>
<?php if ($brandLogoUrl !== ''): ?><form method="post" onsubmit="return confirm('Hapus logo header dan kembalikan tulisan PluQ-i?')"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="delete_brand_logo"><div class="actions"><button type="submit">Hapus logo dan gunakan tulisan</button></div></form><?php endif; ?>
<?php else: ?><p class="muted">Akun ini hanya dapat melihat pengaturan. ADMIN atau SUPER_ADMIN diperlukan untuk mengganti logo.</p><?php endif; ?></section>
<section class="panel"><h2>Icon Game List</h2>
<p class="muted">Hanya game yang dicentang dan tersimpan aktif pada menu <a href="/admin/game-mappings.php">Game List</a> yang tampil di sini. Unggah icon untuk mengisi kolom <code>games.icon_url</code> yang dipakai katalog halaman depan. Format JPG/PNG/WebP, maksimal 5 MB. Setiap icon baru akan disimpan lokal dan dikomit ke repositori GitHub/branch yang dikonfigurasi server. Sinkronisasi GitHub harus dikonfigurasi sebelum unggahan dapat disimpan. Hapus hanya menghapus icon dari katalog; file yang sudah dikomit ke GitHub tidak dihapus otomatis.</p>
<p class="dimension-hint"><strong>Saran ukuran icon: 512 × 512 px (persegi/1:1).</strong> Gunakan gambar yang fokus pada logo game, dengan sedikit ruang kosong di sekelilingnya agar tidak terpotong saat ditampilkan. Ukuran ini adalah rekomendasi, bukan batas wajib.</p>
<?php if (!$activeGames): ?><p class="muted">Belum ada game aktif. Pilih dan simpan game terlebih dahulu di Game List.</p><?php else: ?>
<div class="icon-matrix">
<?php foreach ($activeGames as $game): ?><article class="icon-card">
<div class="icon-card-head"><?php if (!empty($game['icon_url'])): ?><img class="game-icon-thumb" src="<?=admin_escape($game['icon_url'])?>" alt="<?=admin_escape($game['name'])?>" loading="lazy"><?php else: ?><div class="game-icon-thumb" style="display:grid;place-items:center;color:var(--muted);font-size:11px;text-align:center">Belum ada icon</div><?php endif; ?><div><strong><?=admin_escape($game['name'])?></strong><div class="muted small"><?=admin_escape($game['slug'])?></div></div></div>
<?php if ($canManage): ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="save_game_icon"><input type="hidden" name="game_id" value="<?=(int)$game['id']?>"><label>File icon (JPG/PNG/WebP, maks. 5 MB)<input type="file" name="game_icon_file" accept="image/jpeg,image/png,image/webp" required></label><div class="actions"><button type="submit">Unggah/Ganti</button></div></form>
<?php if (!empty($game['icon_url'])): ?><form method="post" onsubmit="return confirm('Hapus icon game ini? Game tetap ada di Game List.')"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="delete_game_icon"><input type="hidden" name="game_id" value="<?=(int)$game['id']?>"><button type="submit">Hapus icon</button></form><?php endif; ?>
<?php else: ?><span class="muted">Hanya lihat</span><?php endif; ?>
</article><?php endforeach; ?>
</div><?php endif; ?></section>
<section class="panel"><h2>Tata letak katalog game</h2>
<p class="muted">Atur jumlah kolom desktop dan ponsel serta ukuran bingkai icon. Perubahan tersimpan di database dan langsung dipakai halaman depan. Tablet tetap menggunakan 3 kolom.</p>
<?php if ($canManage): ?>
<form method="post"><input type="hidden" name="csrf" value="<?=admin_escape($csrf)?>"><input type="hidden" name="action" value="save_catalog_layout">
<div class="grid">
<label>Jumlah game per baris (desktop)
<select name="grid_columns">
<?php foreach ([3,4,5,6] as $option): ?><option value="<?=$option?>" <?=$gridColumns===$option?'selected':''?>><?=$option?> kolom</option><?php endforeach; ?>
</select><span class="dimension-hint">Berlaku pada layar lebih lebar dari 900 px.</span></label>
<label>Jumlah game per baris (ponsel)
<select name="mobile_grid_columns">
<?php foreach ([2,3,4] as $option): ?><option value="<?=$option?>" <?=$mobileGridColumns===$option?'selected':''?>><?=$option?> kolom</option><?php endforeach; ?>
</select><span class="dimension-hint">Default 3 kolom pada layar hingga 560 px. Pilih 2 jika ingin ikon lebih besar.</span></label>
<label>Ukuran icon
<select name="icon_size">
<?php foreach ([80,84,88,92,96,100,104,108,112,116,120] as $option): ?><option value="<?=$option?>" <?=$iconSize===$option?'selected':''?>><?=$option?> × <?=$option?> px</option><?php endforeach; ?>
</select><span class="dimension-hint">Default 100 px. Bingkai dan gambar icon ikut menyesuaikan.</span></label>
</div><div class="actions"><button type="submit">Simpan tata letak</button></div>
</form>
<?php else: ?><p class="muted">Akun ini hanya dapat melihat pengaturan. ADMIN atau SUPER_ADMIN diperlukan untuk mengubah tata letak.</p><?php endif; ?>
</section>
<section class="panel"><h2>Catatan tampilan</h2><ul><li>Gunakan gambar landscape yang responsif untuk banner Mobile Legends, HOK, Free Fire, PUBG, atau promosi lain.</li><li>Slider publik harus menampilkan banner aktif, dengan tombol sebelumnya/berikutnya, indikator slide, perulangan, jeda saat kursor berada di atas slider, dan dukungan geser pada ponsel.</li><li>Unggahan dibatasi ke JPG/PNG/WebP, maksimal 5 MB dan pemeriksaan dimensi; file diberi nama acak dan disimpan di public/uploads/banners.</li><li>Nonaktifkan hanya menyembunyikan banner. Hapus menghapus baris banner dan file lokal yang cocok; gambar pada URL eksternal tidak dihapus dari situs asalnya.</li><li>Game yang dicentang/aktif pada Game List muncul pada bagian Icon Game List. Menghapus icon mengosongkan icon_url tanpa menghapus game.</li><li>Icon lokal berada di public/uploads/game-icons; pastikan PHP dapat menulis folder tersebut. Pengujian visual slider dan icon di browser masih perlu dilakukan.</li></ul></section>
</main></body></html>
