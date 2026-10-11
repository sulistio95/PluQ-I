<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require PLUQI_ROOT . '/config/database.php';
require PLUQI_ROOT . '/app/services/catalog_sync.php';

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
        $result = $code === 'DIGI'
            ? sync_digiflazz_catalog($pdo)
            : sync_vipayment_catalog($pdo);
        if (!empty($result['skipped'])) {
            fwrite(STDOUT, $code . ": skipped (provider disabled).\n");
            continue;
        }
        fwrite(STDOUT, sprintf(
            "%s: received %d products; mapped %d to configured games%s\n",
            $code,
            (int) $result['seen'],
            (int) $result['mapped'],
            isset($result['tier']) ? '; VIPayment tier ' . $result['tier'] : ''
        ));
    } catch (Throwable $e) {
        $failed = true;
        fwrite(STDERR, $code . ": catalog sync failed; inspect server logs and provider configuration.\n");
    }
}

exit($failed ? 1 : 0);
