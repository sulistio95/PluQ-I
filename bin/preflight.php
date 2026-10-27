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

$appEnv = strtolower(trim((string) (getenv('APP_ENV') ?: 'production')));
$isLocalEnvironment = $appEnv === 'local';
$appUrl = (string) $config['url'];
$usesHttps = str_starts_with(strtolower($appUrl), 'https://');
$add(
    'APP_URL uses HTTPS',
    $isLocalEnvironment || $usesHttps,
    $isLocalEnvironment ? 'not required for local development' : ($usesHttps ? 'configured (value not echoed)' : ($appUrl !== '' ? 'HTTPS required outside local development' : 'not configured'))
);
$appKey = (string) getenv('APP_KEY');
$add('APP_KEY strength', strlen($appKey) >= 32, strlen($appKey) >= 32 ? 'present; value hidden' : 'missing or shorter than 32 characters');
$schedulerToken = (string) $config['scheduler_token'];
$add('SCHEDULER_TOKEN strength', strlen($schedulerToken) >= 32, strlen($schedulerToken) >= 32 ? 'present; value hidden' : 'missing or shorter than 32 characters');
$debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$add(
    'APP_DEBUG disabled',
    $isLocalEnvironment || !$debug,
    $isLocalEnvironment ? 'debug allowed for local development' : ($debug ? 'must be false outside local development' : 'disabled')
);
$checkoutPaymentsEnabled = filter_var(getenv('CHECKOUT_PAYMENTS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$add('Checkout payments stay disabled', !$checkoutPaymentsEnabled, $checkoutPaymentsEnabled ? 'WARNING: enabled; turn off until staging checks pass' : 'disabled');
$supplierEnabled = filter_var(getenv('SUPPLIER_ORDERS_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$add('Supplier ordering stays disabled', !$supplierEnabled, $supplierEnabled ? 'WARNING: enabled; turn off until staging checks pass' : 'disabled');
$backupEnabled = filter_var(getenv('SETTLEMENT_BACKUP_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$add('Settlement backup stays disabled', !$backupEnabled, $backupEnabled ? 'WARNING: enabled; verify OAuth and recovery before enabling' : 'disabled');

try {
    $pdo = database();
    $add('Database connection', true, 'connected');
    $requiredTables = [
        'schema_migrations', 'admin_users', 'games', 'providers', 'products',
        'orders', 'payment_transactions', 'job_queue', 'audit_logs',
        'supplier_transactions', 'refunds', 'pricing_rules', 'game_provider_mappings',
        'game_fields', 'game_field_options', 'provider_products', 'product_variants',
        'settings', 'banners', 'excluded_services', 'webhook_events',
        'account_validations', 'admin_login_attempts', 'provider_budget_reservations',
        'game_username_check_cache', 'game_username_check_limits', 'order_ratings',
    ];
    $tableQuery = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :name'
    );
    $schemaPresent = false;
    foreach ($requiredTables as $table) {
        $tableQuery->execute(['name' => $table]);
        $exists = (int) $tableQuery->fetchColumn() === 1;
        if ($table === 'schema_migrations') $schemaPresent = $exists;
        $add('Database table: ' . $table, $exists, $exists ? 'present' : 'missing; apply schema/migrations in order');
    }
    if ($schemaPresent) {
        $applied = $pdo->query('SELECT migration_name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        foreach ([
            '001_shared_hosting_foundation',
            '002_catalog_and_operations',
            '003_checkout_payment',
            '004_supplier_manual_review_status',
            '005_order_manual_review_status',
            '008_admin_login_rate_limit',
            '009_refund_order_latest_index',
            '010_checkout_payload_fingerprint',
            '011_provider_budget_reservations',
            '012_pricing_rules_hybrid',
            '013_game_username_check_protection',
            '014_order_ratings_and_supplier_quality',
        ] as $migration) {
            $ok = in_array($migration, $applied, true);
            $add('Database migration: ' . $migration, $ok, $ok ? 'applied' : 'missing; apply migrations in order');
        }
        if ($backupEnabled) {
            foreach (['006_monthly_settlements', '007_admin_drive_folder_setting'] as $migration) {
                $ok = in_array($migration, $applied, true);
                $add('Database migration: ' . $migration, $ok, $ok ? 'applied' : 'required when settlement backup is enabled');
            }
            foreach (['monthly_settlement_exports', 'app_settings'] as $table) {
                $tableQuery->execute(['name' => $table]);
                $exists = (int) $tableQuery->fetchColumn() === 1;
                $add('Database table: ' . $table, $exists, $exists ? 'present' : 'required when settlement backup is enabled');
            }
        }
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
