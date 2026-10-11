<?php
declare(strict_types=1);

/**
 * Monthly transaction settlement CSV export.
 * Run on day 1 after applying migration 006. Temporary CSV files are removed
 * in all cases; the settlement row is marked UPLOADED only after Drive confirms.
 *
 * Usage: php bin/settle-monthly.php [--period=YYYY-MM]
 * --period is intended for controlled testing/recovery; a previously uploaded
 * period is never uploaded a second time.
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(2);
}

function settlement_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function settlement_curl(string $url, string $method, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('Google API request failed (HTTP ' . $status . ')' . ($error !== '' ? ': ' . $error : ''));
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Google API returned invalid JSON.');
    }
    return $data;
}

function settlement_access_token(): string
{
    $id = trim((string) getenv('GOOGLE_OAUTH_CLIENT_ID'));
    $secret = trim((string) getenv('GOOGLE_OAUTH_CLIENT_SECRET'));
    $refresh = trim((string) getenv('GOOGLE_OAUTH_REFRESH_TOKEN'));
    if ($id === '' || $secret === '' || $refresh === '') {
        throw new RuntimeException('Google Drive OAuth credentials are not configured.');
    }
    $data = settlement_curl('https://oauth2.googleapis.com/token', 'POST', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'client_id' => $id,
        'client_secret' => $secret,
        'refresh_token' => $refresh,
        'grant_type' => 'refresh_token',
    ]));
    $token = trim((string) ($data['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Google OAuth response did not contain an access token.');
    }
    return $token;
}

function settlement_find_drive_file(string $name, string $period, string $token): ?string
{
    $q = "appProperties has { key='pluqSettlementPeriod' and value='" . addcslashes($period, "\\'") . "' } and trashed=false";
    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => $q,
        'pageSize' => 10,
        'fields' => 'files(id,name,mimeType)',
        'spaces' => 'drive',
    ]);
    $data = settlement_curl($url, 'GET', ['Authorization: Bearer ' . $token]);
    foreach (($data['files'] ?? []) as $file) {
        if (!is_array($file) || ($file['name'] ?? '') !== $name || ($file['mimeType'] ?? '') !== 'text/csv' || empty($file['id'])) continue;
        return (string) $file['id'];
    }
    return null;
}

function settlement_upload_drive(string $path, string $name, string $period, string $token, string $folderId): string
{
    $metadata = ['name' => $name, 'mimeType' => 'text/csv', 'appProperties' => ['pluqSettlementPeriod' => $period]];
    if ($folderId !== '') {
        $metadata['parents'] = [$folderId];
    }
    $boundary = 'pluq_i_' . bin2hex(random_bytes(12));
    $csv = file_get_contents($path);
    if ($csv === false) {
        throw new RuntimeException('Cannot read temporary settlement file.');
    }
    $body = '--' . $boundary . "\r\n"
        . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
        . json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\r\n"
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/csv; charset=UTF-8\r\n\r\n"
        . $csv . "\r\n"
        . '--' . $boundary . "--\r\n";
    $data = settlement_curl('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,mimeType,size', 'POST', [
        'Authorization: Bearer ' . $token,
        'Content-Type: multipart/related; boundary=' . $boundary,
    ], $body);
    $fileId = trim((string) ($data['id'] ?? ''));
    if ($fileId === '' || ($data['name'] ?? '') !== $name || ($data['mimeType'] ?? '') !== 'text/csv') {
        throw new RuntimeException('Google Drive upload response could not be verified.');
    }
    return $fileId;
}

$period = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--period=')) {
        $period = substr($arg, 9);
    } else {
        settlement_fail('Usage: php bin/settle-monthly.php [--period=YYYY-MM]');
    }
}
$now = new DateTimeImmutable('now', new DateTimeZone($config['timezone']));
if ($period === null) {
    if ($now->format('d') !== '01') {
        settlement_fail('This job runs on day 1 only. Use --period=YYYY-MM for controlled recovery.');
    }
    $month = $now->modify('first day of last month');
} else {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period)) {
        settlement_fail('Invalid period. Expected YYYY-MM.');
    }
    $month = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01', new DateTimeZone($config['timezone']));
    if (!$month || $month->format('Y-m') !== $period || $month >= $now->modify('first day of this month')) {
        settlement_fail('Period must be a valid completed month.');
    }
}
$start = $month->format('Y-m-01');
$endExclusive = $month->modify('first day of next month')->format('Y-m-01');
$fileName = 'PluQ-settlement-' . $month->format('Y-m') . '.csv';
$pdo = database();

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM monthly_settlement_exports WHERE period_start=:start AND period_end=:end FOR UPDATE");
    $stmt->execute(['start' => $start, 'end' => $month->modify('last day of this month')->format('Y-m-d')]);
    $existing = $stmt->fetch();
    if ($existing && $existing['status'] === 'UPLOADED') {
        $pdo->commit();
        fwrite(STDOUT, 'Already uploaded: ' . $fileName . ' (Drive ID ' . (string) $existing['drive_file_id'] . ')' . PHP_EOL);
        exit(0);
    }
    if ($existing) {
        $stmt = $pdo->prepare("UPDATE monthly_settlement_exports SET status='PROCESSING',file_name=:file,last_error=NULL WHERE id=:id");
        $stmt->execute(['file' => $fileName, 'id' => $existing['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO monthly_settlement_exports (period_start,period_end,status,file_name) VALUES (:start,:end,'PROCESSING',:file)");
        $stmt->execute(['start' => $start, 'end' => $month->modify('last day of this month')->format('Y-m-d'), 'file' => $fileName]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    settlement_fail('Cannot reserve settlement period. Apply migration 006 and inspect database state. Details: ' . $e->getMessage());
}

$tempPath = tempnam(sys_get_temp_dir(), 'pluq-settlement-');
if ($tempPath === false) settlement_fail('Cannot create temporary export file.');
try {
    $query = $pdo->prepare("
        SELECT
            o.public_id AS order_number,
            o.created_at AS order_created_at,
            o.paid_at,
            g.name AS game_name,
            p.name AS product_name,
            pr.code AS provider_api,
            o.base_price,
            o.selling_price,
            o.admin_fee,
            (o.selling_price + o.admin_fee) AS customer_total,
            o.payment_status,
            o.order_status,
            pt.gateway AS payment_gateway,
            pt.merchant_order_id,
            pt.gateway_reference,
            st.reference_id AS supplier_reference,
            st.provider_reference,
            st.status AS supplier_status,
            (SELECT r.status FROM refunds r WHERE r.order_id=o.id ORDER BY r.id DESC LIMIT 1) AS latest_refund_status,
            o.fulfilled_at
        FROM orders o
        JOIN games g ON g.id=o.game_id
        JOIN products p ON p.id=o.product_id
        JOIN providers pr ON pr.id=o.provider_id
        LEFT JOIN payment_transactions pt ON pt.order_id=o.id
        LEFT JOIN supplier_transactions st ON st.order_id=o.id
        WHERE o.created_at >= :start AND o.created_at < :end
        ORDER BY o.created_at ASC, o.id ASC
    ");
    $query->execute(['start' => $start . ' 00:00:00', 'end' => $endExclusive . ' 00:00:00']);
    $handle = fopen($tempPath, 'wb');
    if ($handle === false) throw new RuntimeException('Cannot open temporary CSV file.');
    fwrite($handle, "\xEF\xBB\xBF");
    $header = [
        'order_number','order_created_at','paid_at','game_name','product_name','provider_api',
        'base_price','selling_price','admin_fee','customer_total','payment_status','order_status',
        'payment_gateway','merchant_order_id','gateway_reference','supplier_reference',
        'provider_reference','supplier_status','latest_refund_status','fulfilled_at',
    ];
    fputcsv($handle, $header);
    $count = 0;
    $total = 0.0;
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
        if ($row['payment_status'] === 'PAID') $total += (float) $row['customer_total'];
        fputcsv($handle, array_values($row));
        $count++;
    }
    fclose($handle);

    $token = settlement_access_token();
    $folderId = trim((string) getenv('GOOGLE_DRIVE_FOLDER_ID'));
    $driveFileId = settlement_find_drive_file($fileName, $month->format('Y-m'), $token)
        ?? settlement_upload_drive($tempPath, $fileName, $month->format('Y-m'), $token, $folderId);
    $stmt = $pdo->prepare("UPDATE monthly_settlement_exports SET status='UPLOADED',drive_file_id=:drive,row_count=:count,total_amount=:total,uploaded_at=NOW(),last_error=NULL WHERE period_start=:start AND period_end=:end");
    $stmt->execute([
        'drive' => $driveFileId, 'count' => $count, 'total' => number_format($total, 2, '.', ''),
        'start' => $start, 'end' => $month->modify('last day of this month')->format('Y-m-d'),
    ]);
    fwrite(STDOUT, json_encode([
        'status' => 'UPLOADED',
        'period' => $month->format('Y-m'),
        'file_name' => $fileName,
        'drive_file_id' => $driveFileId,
        'rows' => $count,
        'sum_customer_total' => number_format($total, 2, '.', ''),
        'local_temporary_file_removed' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $e) {
    $stmt = $pdo->prepare("UPDATE monthly_settlement_exports SET status='FAILED',last_error=:error WHERE period_start=:start AND period_end=:end AND status<>'UPLOADED'");
    $stmt->execute([
        'error' => mb_substr($e->getMessage(), 0, 2000),
        'start' => $start, 'end' => $month->modify('last day of this month')->format('Y-m-d'),
    ]);
    settlement_fail('Settlement backup failed; no uploaded state recorded. Temporary export will be removed. Details: ' . $e->getMessage());
} finally {
    if (is_file($tempPath)) unlink($tempPath);
}
