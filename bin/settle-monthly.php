<?php
declare(strict_types=1);

/**
 * Creates a formatted Google Sheets bookkeeping pack for the previous month.
 * Run on day 1. Controlled recovery: php bin/settle-monthly.php --period=YYYY-MM
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(2);
}
if (!filter_var(getenv('SETTLEMENT_BACKUP_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "Settlement backup is disabled. Verify Google OAuth and the destination folder before enabling it.\n");
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
        $detail = '';
        if (is_string($raw) && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) $detail = (string) ($parsed['error']['message'] ?? '');
        }
        throw new RuntimeException('Google API request failed (HTTP ' . $status . ')' .
            ($detail !== '' ? ': ' . $detail : ($error !== '' ? ': ' . $error : '')));
    }
    if ($raw === '' || $raw === false) return [];
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) throw new RuntimeException('Google API returned invalid JSON.');
    return $data;
}
function settlement_access_token(): string
{
    $id = trim((string) getenv('GOOGLE_OAUTH_CLIENT_ID'));
    $secret = trim((string) getenv('GOOGLE_OAUTH_CLIENT_SECRET'));
    $refresh = trim((string) getenv('GOOGLE_OAUTH_REFRESH_TOKEN'));
    if ($id === '' || $secret === '' || $refresh === '') {
        throw new RuntimeException('Google Drive/Sheets OAuth credentials are not configured.');
    }
    $data = settlement_curl('https://oauth2.googleapis.com/token', 'POST', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'client_id' => $id, 'client_secret' => $secret,
        'refresh_token' => $refresh, 'grant_type' => 'refresh_token',
    ]));
    $token = trim((string) ($data['access_token'] ?? ''));
    if ($token === '') throw new RuntimeException('Google OAuth response did not contain an access token.');
    return $token;
}
function settlement_find_drive_file(string $name, string $period, string $token): ?string
{
    $q = "appProperties has { key='pluqSettlementPeriod' and value='" .
        addcslashes($period, "\\'") . "' } and trashed=false";
    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q' => $q, 'pageSize' => 20, 'fields' => 'files(id,name,mimeType)',
        'spaces' => 'drive',
    ]);
    $data = settlement_curl($url, 'GET', ['Authorization: Bearer ' . $token]);
    foreach (($data['files'] ?? []) as $file) {
        if (is_array($file) && ($file['name'] ?? '') === $name &&
            ($file['mimeType'] ?? '') === 'application/vnd.google-apps.spreadsheet' &&
            !empty($file['id'])) return (string) $file['id'];
    }
    return null;
}
function settlement_cell_value(mixed $value): array
{
    if ($value === null || $value === '') return ['userEnteredValue' => ['stringValue' => '']];
    if (is_int($value) || is_float($value)) return ['userEnteredValue' => ['numberValue' => $value]];
    return ['userEnteredValue' => ['stringValue' => (string) $value]];
}
function settlement_update_values(string $spreadsheetId, string $range, array $rows, string $token): void
{
    settlement_curl(
        'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values:batchUpdate',
        'POST',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode(['valueInputOption' => 'RAW', 'data' => [['range' => $range, 'values' => $rows]]],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
}
function settlement_format(string $spreadsheetId, array $requests, string $token): void
{
    if (!$requests) return;
    settlement_curl(
        'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . ':batchUpdate',
        'POST',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode(['requests' => $requests], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
}
function settlement_create_sheet(string $title, string $period, array $summary, array $details, array $reconciliation, string $folderId, string $token): string
{
    $spreadsheet = settlement_curl('https://sheets.googleapis.com/v4/spreadsheets', 'POST', [
        'Authorization: Bearer ' . $token, 'Content-Type: application/json',
    ], json_encode([
        'properties' => ['title' => $title, 'locale' => 'id_ID', 'timeZone' => 'Asia/Jakarta'],
        'sheets' => [
            ['properties' => ['sheetId' => 0, 'title' => 'Ringkasan Bulanan', 'gridProperties' => ['frozenRowCount' => 4]]],
            ['properties' => ['sheetId' => 1, 'title' => 'Rincian Transaksi', 'gridProperties' => ['frozenRowCount' => 1]]],
            ['properties' => ['sheetId' => 2, 'title' => 'Rekonsiliasi', 'gridProperties' => ['frozenRowCount' => 1]]],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $id = trim((string) ($spreadsheet['spreadsheetId'] ?? ''));
    if ($id === '') throw new RuntimeException('Google Sheets did not return a spreadsheet ID.');

    settlement_update_values($id, "'Ringkasan Bulanan'!A1", $summary, $token);
    settlement_update_values($id, "'Rincian Transaksi'!A1", $details, $token);
    settlement_update_values($id, "'Rekonsiliasi'!A1", $reconciliation, $token);

    $navy = ['red' => 0.10, 'green' => 0.18, 'blue' => 0.32];
    $blue = ['red' => 0.16, 'green' => 0.35, 'blue' => 0.58];
    $pale = ['red' => 0.91, 'green' => 0.94, 'blue' => 0.98];
    $white = ['red' => 1, 'green' => 1, 'blue' => 1];
    $requests = [
        ['mergeCells' => ['range' => ['sheetId' => 0, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => 4], 'mergeType' => 'MERGE_ALL']],
        ['mergeCells' => ['range' => ['sheetId' => 0, 'startRowIndex' => 1, 'endRowIndex' => 2, 'startColumnIndex' => 0, 'endColumnIndex' => 4], 'mergeType' => 'MERGE_ALL']],
        ['repeatCell' => ['range' => ['sheetId' => 0, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => 4],
            'cell' => ['userEnteredFormat' => ['backgroundColor' => $navy, 'textFormat' => ['foregroundColor' => $white, 'bold' => true, 'fontSize' => 18], 'verticalAlignment' => 'MIDDLE']],
            'fields' => 'userEnteredFormat(backgroundColor,textFormat,verticalAlignment)']],
        ['repeatCell' => ['range' => ['sheetId' => 0, 'startRowIndex' => 1, 'endRowIndex' => 2, 'startColumnIndex' => 0, 'endColumnIndex' => 4],
            'cell' => ['userEnteredFormat' => ['backgroundColor' => $navy, 'textFormat' => ['foregroundColor' => $white, 'fontSize' => 11], 'verticalAlignment' => 'MIDDLE']],
            'fields' => 'userEnteredFormat(backgroundColor,textFormat,verticalAlignment)']],
        ['repeatCell' => ['range' => ['sheetId' => 0, 'startRowIndex' => 3, 'endRowIndex' => 4, 'startColumnIndex' => 0, 'endColumnIndex' => 2],
            'cell' => ['userEnteredFormat' => ['backgroundColor' => $blue, 'textFormat' => ['foregroundColor' => $white, 'bold' => true]]],
            'fields' => 'userEnteredFormat(backgroundColor,textFormat)']],
        ['repeatCell' => ['range' => ['sheetId' => 0, 'startRowIndex' => 12, 'endRowIndex' => 13, 'startColumnIndex' => 0, 'endColumnIndex' => 3],
            'cell' => ['userEnteredFormat' => ['backgroundColor' => $blue, 'textFormat' => ['foregroundColor' => $white, 'bold' => true]]],
            'fields' => 'userEnteredFormat(backgroundColor,textFormat)']],
        ['repeatCell' => ['range' => ['sheetId' => 1, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => 20],
            'cell' => ['userEnteredFormat' => ['backgroundColor' => $navy, 'textFormat' => ['foregroundColor' => $white, 'bold' => true], 'wrapStrategy' => 'WRAP', 'verticalAlignment' => 'MIDDLE']],
            'fields' => 'userEnteredFormat(backgroundColor,textFormat,wrapStrategy,verticalAlignment)']],
        ['repeatCell' => ['range' => ['sheetId' => 2, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => 8],
            'cell' => ['userEnteredFormat' => ['backgroundColor' => $navy, 'textFormat' => ['foregroundColor' => $white, 'bold' => true], 'wrapStrategy' => 'WRAP']],
            'fields' => 'userEnteredFormat(backgroundColor,textFormat,wrapStrategy)']],
        ['addBanding' => ['bandedRange' => ['range' => ['sheetId' => 1, 'startRowIndex' => 0, 'endRowIndex' => max(2, count($details)), 'startColumnIndex' => 0, 'endColumnIndex' => 20],
            'rowProperties' => ['headerColor' => $navy, 'firstBandColor' => $white, 'secondBandColor' => $pale]]]],
        ['addBanding' => ['bandedRange' => ['range' => ['sheetId' => 2, 'startRowIndex' => 0, 'endRowIndex' => max(2, count($reconciliation)), 'startColumnIndex' => 0, 'endColumnIndex' => 8],
            'rowProperties' => ['headerColor' => $navy, 'firstBandColor' => $white, 'secondBandColor' => $pale]]]],
        ['repeatCell' => ['range' => ['sheetId' => 1, 'startRowIndex' => 1, 'endRowIndex' => max(2, count($details)), 'startColumnIndex' => 7, 'endColumnIndex' => 11],
            'cell' => ['userEnteredFormat' => ['numberFormat' => ['type' => 'CURRENCY', 'pattern' => '"Rp" #,##0.00']]],
            'fields' => 'userEnteredFormat.numberFormat']],
        ['repeatCell' => ['range' => ['sheetId' => 0, 'startRowIndex' => 4, 'endRowIndex' => 11, 'startColumnIndex' => 1, 'endColumnIndex' => 2],
            'cell' => ['userEnteredFormat' => ['numberFormat' => ['type' => 'CURRENCY', 'pattern' => '"Rp" #,##0.00']]],
            'fields' => 'userEnteredFormat.numberFormat']],
        ['updateDimensionProperties' => ['range' => ['sheetId' => 1, 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => 20],
            'properties' => ['pixelSize' => 150], 'fields' => 'pixelSize']],
        ['updateDimensionProperties' => ['range' => ['sheetId' => 1, 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => 1],
            'properties' => ['pixelSize' => 55], 'fields' => 'pixelSize']],
        ['updateDimensionProperties' => ['range' => ['sheetId' => 0, 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => 1],
            'properties' => ['pixelSize' => 300], 'fields' => 'pixelSize']],
        ['updateDimensionProperties' => ['range' => ['sheetId' => 0, 'dimension' => 'COLUMNS', 'startIndex' => 1, 'endIndex' => 2],
            'properties' => ['pixelSize' => 190], 'fields' => 'pixelSize']],
    ];
    settlement_format($id, $requests, $token);

    $current = settlement_curl('https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?fields=parents', 'GET', [
        'Authorization: Bearer ' . $token,
    ]);
    $parents = $current['parents'] ?? [];
    $query = ['addParents' => $folderId, 'fields' => 'id,name,mimeType,parents'];
    if ($parents) $query['removeParents'] = implode(',', $parents);
    settlement_curl('https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?' . http_build_query($query), 'PATCH', [
        'Authorization: Bearer ' . $token, 'Content-Type: application/json',
    ], '{}');
    settlement_curl('https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?fields=id,name,mimeType,appProperties', 'PATCH', [
        'Authorization: Bearer ' . $token, 'Content-Type: application/json',
    ], json_encode(['appProperties' => ['pluqSettlementPeriod' => $period]], JSON_THROW_ON_ERROR));
    return $id;
}

$period = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--period=')) $period = substr($arg, 9);
    else settlement_fail('Usage: php bin/settle-monthly.php [--period=YYYY-MM]');
}
$now = new DateTimeImmutable('now', new DateTimeZone($config['timezone']));
if ($period === null) {
    if ($now->format('d') !== '01') settlement_fail('This job runs on day 1 only. Use --period=YYYY-MM for controlled recovery.');
    $month = $now->modify('first day of last month');
} else {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period)) settlement_fail('Invalid period. Expected YYYY-MM.');
    $month = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01', new DateTimeZone($config['timezone']));
    if (!$month || $month->format('Y-m') !== $period || $month >= $now->modify('first day of this month')) {
        settlement_fail('Period must be a valid completed month.');
    }
}
$periodKey = $month->format('Y-m');
$start = $month->format('Y-m-01');
$endDate = $month->modify('last day of this month')->format('Y-m-d');
$endExclusive = $month->modify('first day of next month')->format('Y-m-01');
$fileName = 'PluQ-Pembukuan-' . $periodKey;
$pdo = database();

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM monthly_settlement_exports WHERE period_start=:start AND period_end=:end FOR UPDATE');
    $stmt->execute(['start' => $start, 'end' => $endDate]);
    $existing = $stmt->fetch();
    if ($existing && $existing['status'] === 'UPLOADED') {
        $pdo->commit();
        fwrite(STDOUT, 'Already uploaded: ' . $fileName . ' (Google Sheets ID ' . (string) $existing['drive_file_id'] . ')' . PHP_EOL);
        exit(0);
    }
    if ($existing && $existing['status'] === 'PROCESSING' && strtotime((string) $existing['updated_at']) > time() - 1800) {
        $pdo->commit();
        settlement_fail('This settlement period is already being processed. Wait 30 minutes before controlled recovery.');
    }
    if ($existing) {
        $stmt = $pdo->prepare("UPDATE monthly_settlement_exports SET status='PROCESSING',file_name=:file,last_error=NULL WHERE id=:id");
        $stmt->execute(['file' => $fileName, 'id' => $existing['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO monthly_settlement_exports (period_start,period_end,status,file_name) VALUES (:start,:end,'PROCESSING',:file)");
        $stmt->execute(['start' => $start, 'end' => $endDate, 'file' => $fileName]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    settlement_fail('Cannot reserve settlement period. Apply migration 006 and inspect database state. Details: ' . $e->getMessage());
}

try {
    $query = $pdo->prepare("
        SELECT o.public_id AS order_number, o.created_at AS order_created_at, o.paid_at,
            g.name AS game_name, p.name AS product_name, pr.code AS provider_api,
            o.base_price, o.selling_price, o.admin_fee, (o.selling_price + o.admin_fee) AS customer_total,
            o.payment_status, o.order_status, pt.gateway AS payment_gateway, pt.merchant_order_id,
            pt.gateway_reference, st.reference_id AS supplier_reference, st.provider_reference,
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
    $orders = $query->fetchAll(PDO::FETCH_ASSOC);

    $count = count($orders);
    $paidCount = 0; $paidSales = 0.0; $paidCost = 0.0; $paidAdminFee = 0.0; $paidTotal = 0.0;
    $paymentSummary = [];
    $orderSummary = [];
    $details = [[
        'No.', 'Nomor Pesanan', 'Dibuat Pada', 'Dibayar Pada', 'Game', 'Produk', 'API Supplier',
        'Biaya Pokok (Rp)', 'Harga Jual (Rp)', 'Biaya Admin (Rp)', 'Total Dibayar (Rp)',
        'Status Pembayaran', 'Status Pesanan', 'Status Supplier', 'Status Refund',
        'Gateway', 'ID Pesanan Gateway', 'Referensi Gateway', 'Referensi Supplier', 'Selesai Pada',
    ]];
    $reviewRows = [['Nomor Pesanan', 'Tanggal Dibuat', 'Dibayar Pada', 'Status Pembayaran', 'Status Pesanan', 'Status Supplier', 'Status Refund', 'Total Transaksi (Rp)']];
    foreach ($orders as $i => $o) {
        $payment = (string) ($o['payment_status'] ?? 'UNKNOWN');
        $orderStatus = (string) ($o['order_status'] ?? 'UNKNOWN');
        $supplierStatus = (string) ($o['supplier_status'] ?? 'N/A');
        $refundStatus = (string) ($o['latest_refund_status'] ?? 'N/A');
        $total = (float) $o['customer_total'];
        $paymentSummary[$payment] ??= ['count' => 0, 'amount' => 0.0];
        $paymentSummary[$payment]['count']++;
        if ($payment === 'PAID') $paymentSummary[$payment]['amount'] += $total;
        $orderSummary[$orderStatus] = ($orderSummary[$orderStatus] ?? 0) + 1;
        if ($payment === 'PAID') {
            $paidCount++; $paidSales += (float) $o['selling_price']; $paidCost += (float) $o['base_price'];
            $paidAdminFee += (float) $o['admin_fee']; $paidTotal += $total;
        }
        $details[] = [
            $i + 1, $o['order_number'], $o['order_created_at'], $o['paid_at'], $o['game_name'], $o['product_name'],
            $o['provider_api'], (float) $o['base_price'], (float) $o['selling_price'], (float) $o['admin_fee'], $total,
            $payment, $orderStatus, $supplierStatus, $refundStatus, $o['payment_gateway'] ?? '',
            $o['merchant_order_id'] ?? '', $o['gateway_reference'] ?? '', $o['supplier_reference'] ?? '', $o['fulfilled_at'],
        ];
        if ($payment !== 'PAID' || in_array($orderStatus, ['MANUAL_REVIEW','REFUND_PENDING','FAILED'], true) ||
            in_array($refundStatus, ['PENDING','REFUND_PENDING','FAILED'], true)) {
            $reviewRows[] = [$o['order_number'], $o['order_created_at'], $o['paid_at'], $payment, $orderStatus,
                $supplierStatus, $refundStatus, $total];
        }
    }
    $summary = [
        ['PluQ | LAPORAN PEMBUKUAN BULANAN', '', '', ''],
        ['Periode: ' . $month->format('F Y') . ' | Mata uang: Rupiah (IDR) | Waktu: ' . $config['timezone'], '', '', ''],
        ['', '', '', ''],
        ['INDIKATOR UTAMA', 'NILAI', '', ''],
        ['Jumlah seluruh pesanan', $count, '', ''],
        ['Pesanan dengan pembayaran PAID', $paidCount, '', ''],
        ['Nilai penjualan produk dari pembayaran PAID (Rp)', round($paidSales, 2), '', ''],
        ['Biaya pokok produk dari pembayaran PAID (Rp)', round($paidCost, 2), '', ''],
        ['Biaya admin tercatat dari pembayaran PAID (Rp)', round($paidAdminFee, 2), '', ''],
        ['Total pembayaran tercatat (harga jual + biaya admin) (Rp)', round($paidTotal, 2), '', ''],
        ['Margin kotor indikatif (harga jual - biaya pokok) (Rp)', round($paidSales - $paidCost, 2), '', ''],
        ['', '', '', ''],
        ['RINGKASAN STATUS PEMBAYARAN', 'JUMLAH PESANAN', 'TOTAL PEMBAYARAN PAID (Rp)', ''],
    ];
    foreach (['PAID','PENDING','FAILED','EXPIRED','REFUND_PENDING','REFUNDED'] as $status) {
        $summary[] = [$status, $paymentSummary[$status]['count'] ?? 0, round($paymentSummary[$status]['amount'] ?? 0, 2), ''];
    }
    $summary[] = ['', '', '', ''];
    $summary[] = ['RINGKASAN STATUS PESANAN', 'JUMLAH PESANAN', '', ''];
    foreach ($orderSummary as $status => $num) $summary[] = [$status, $num, '', ''];
    $summary[] = ['', '', '', ''];
    $summary[] = ['CATATAN PENTING', '', '', ''];
    $summary[] = ['Laporan operasional untuk rekonsiliasi dan bahan pembukuan; bukan perhitungan atau nasihat pajak final.', '', '', ''];
    $summary[] = ['Nilai penjualan tidak otomatis sama dengan dasar pengenaan pajak. Konfirmasikan perlakuan omzet, biaya, biaya gateway, dan refund dengan akuntan/konsultan pajak.', '', '', ''];
    $summary[] = ['Refund tidak dikurangkan otomatis dari ringkasan; periksa lembar Rekonsiliasi dan bukti refund aktual.', '', '', ''];
    $summary[] = ['Transaksi yang belum lunas atau berstatus gagal/manual review ditampilkan untuk pemeriksaan, bukan dianggap pendapatan final.', '', '', ''];

    $reconciliation = $reviewRows;
    if (count($reconciliation) === 1) $reconciliation[] = ['Tidak ada transaksi yang terdeteksi memerlukan rekonsiliasi', '', '', '', '', '', '', ''];

    $token = settlement_access_token();
    $folderStmt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='google_drive_folder_id' LIMIT 1");
    $folderId = trim((string) ($folderStmt->fetchColumn() ?: ''));
    if ($folderId === '') $folderId = trim((string) getenv('GOOGLE_DRIVE_FOLDER_ID'));
    if ($folderId === '') throw new RuntimeException('Google Drive destination folder is not configured in the admin panel or server environment.');

    $driveFileId = settlement_find_drive_file($fileName, $periodKey, $token);
    if ($driveFileId === null) {
        $driveFileId = settlement_create_sheet($fileName, $periodKey, $summary, $details, $reconciliation, $folderId, $token);
    }
    $stmt = $pdo->prepare("UPDATE monthly_settlement_exports SET status='UPLOADED',drive_file_id=:drive,row_count=:count,total_amount=:total,uploaded_at=NOW(),last_error=NULL WHERE period_start=:start AND period_end=:end");
    $stmt->execute([
        'drive' => $driveFileId, 'count' => $count, 'total' => number_format($paidTotal, 2, '.', ''),
        'start' => $start, 'end' => $endDate,
    ]);
    fwrite(STDOUT, json_encode([
        'status' => 'UPLOADED', 'period' => $periodKey, 'file_name' => $fileName,
        'google_sheets_id' => $driveFileId, 'orders' => $count,
        'paid_customer_total' => number_format($paidTotal, 2, '.', ''),
        'sheets' => ['Ringkasan Bulanan', 'Rincian Transaksi', 'Rekonsiliasi'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $e) {
    $stmt = $pdo->prepare("UPDATE monthly_settlement_exports SET status='FAILED',last_error=:error WHERE period_start=:start AND period_end=:end AND status<>'UPLOADED'");
    $stmt->execute(['error' => mb_substr($e->getMessage(), 0, 2000), 'start' => $start, 'end' => $endDate]);
    settlement_fail('Settlement backup failed; inspect admin settlement status. Details: ' . $e->getMessage());
}
