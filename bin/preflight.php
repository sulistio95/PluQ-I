<?php
declare(strict_types=1);

/**
 * Shared-hosting deployment preflight. CLI only; prints checks, never secrets.
 * Run after configuring the private .env and applying the intended migrations.
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$checks = [];
$add = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
};

$add('PHP version', PHP_VERSION_ID >= 80200, 'PHP 8.2 or newer is required; detected ' . PHP_VERSION);
$requiredExtensions = ['pdo_mysql', 'curl', 'openssl', 'mbstring', 'fileinfo'];
foreach ($requiredExtensions as $extension) {
    $add('PHP extension: ' . $extension, extension_loaded($extension), extension_loaded($extension) ? 'loaded' : 'missing');
}

$appUrl = (string) $config['url'];
$add('APP_URL uses HTTPS', str_starts_with(strtolower($appUrl), 'https://'), $appUrl !== '' ? 'configured (value not echoed)' : 'not configured');
$appKey = (string) getenv('APP_KEY');
$add('APP_KEY strength', strlen($appKey) >= 32, strlen($appKey) >= 32 ? 'present; value hidden' : 'missing or shorter than 32 characters');
$schedulerToken = (string) $config['scheduler_token'];
$add('SCHEDULER_TOKEN strength', strlen($schedulerToken) >= 32, strlen($schedulerToken) >= 32 ? 'present; value hidden' : 'missing or shorter than 32 characters');
$debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$add('APP_DEBUG disabled', !$debug, $debug ? 'must be false in production' : 'disabled');
$supplierEnabled = filter_var(getenv('SUPPLIER_ORDERS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$add('Supplier ordering stays disabled', !$supplierEnabled, $supplierEnabled ? 'WARNING: enabled; turn off until staging checks pass' : 'disabled');
$backupEnabled = filter_var(getenv('SETTLEMENT_BACKUP_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$add('Settlement backup stays disabled', !$backupEnabled, $backupEnabled ? 'WARNING: enabled; verify OAuth and recovery before enabling' : 'disabled');

try {
    $pdo = database();
    $add('Database connection', true, 'connected');
    $requiredTables = [
        'schema_migrations', 'admin_users', 'games', 'providers', 'products',
        'orders', 'payment_transactions', 'job_queue', 'supplier_transactions', 'provider_balance_reservations', 'admin_login_attempts',
    ];
    $tableQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name'
    );
    foreach ($requiredTables as $table) {
        $tableQuery->execute(['name' => $table]);
        $exists = (int) $tableQuery->fetchColumn() === 1;
        $add('Database table: ' . $table, $exists, $exists ? 'present' : 'missing; apply schema/migrations in order');
    }
} catch (Throwable $e) {
    $add('Database connection', false, 'failed; check private DB settings and hosting access');
}

$failed = array_values(array_filter($checks, static fn(array $check): bool => !$check['ok']));
foreach ($checks as $check) {
    printf("[%s] %s — %s\n", $check['ok'] ? 'OK' : 'FAIL', $check['name'], $check['detail']);
}
printf("\nResult: %d/%d checks passed.\n", count($checks) - count($failed), count($checks));
if ($failed !== []) {
    fwrite(STDERR, "Preflight failed. Resolve failed checks before continuing deployment.\n");
    exit(1);
}
fwrite(STDOUT, "Preflight passed. This does not verify external payment/provider credentials or production readiness.\n");
