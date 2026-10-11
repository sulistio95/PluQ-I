<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require PLUQI_ROOT . '/config/database.php';
require PLUQI_ROOT . '/app/services/provider_sync.php';

$pdo = database();
$providers = [
    'DIGI' => ['DIGIFLAZZ_USERNAME', 'DIGIFLAZZ_API_KEY'],
    'VIPAY' => ['VIPAYMENT_API_ID', 'VIPAYMENT_API_KEY'],
];
$failed = false;

foreach ($providers as $code => $requiredEnvironment) {
    $configured = true;
    foreach ($requiredEnvironment as $key) {
        if (trim((string) getenv($key)) === '') {
            $configured = false;
            break;
        }
    }
    if (!$configured) {
        fwrite(STDERR, $code . ": skipped (credentials not configured).\n");
        continue;
    }

    try {
        $result = sync_provider_balance($pdo, $code);
        if (!empty($result['skipped'])) {
            fwrite(STDOUT, $code . ": skipped (provider disabled).\n");
            continue;
        }
        fwrite(STDOUT, sprintf("%s: balance %.2f, status %s\n", $result['provider'], $result['balance'], $result['status']));
    } catch (Throwable $e) {
        $failed = true;
        record_provider_balance_error($pdo, $code, $e);
        fwrite(STDERR, $code . ": sync failed; see provider record for the sanitized error.\n");
    }
}

exit($failed ? 1 : 0);
