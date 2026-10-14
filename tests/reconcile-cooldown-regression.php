<?php
declare(strict_types=1);

/**
 * Structural regression guard for admin Duitku status lookup admission.
 *
 * This test prevents accidental removal/reordering of the row-lock + committed
 * STARTED marker and prevents cooldown-denied clicks from generating another
 * lookup audit row. It is not a substitute for a two-process MySQL/HTTP test.
 */
function reconcile_cooldown_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$source = file_get_contents(dirname(__DIR__) . '/app/services/duitku_reconcile.php');
reconcile_cooldown_assert(is_string($source) && $source !== '', 'Could not read admin reconciliation helper.');
$route = file_get_contents(dirname(__DIR__) . '/public/admin/reconcile.php');
reconcile_cooldown_assert(is_string($route) && str_contains($route, 'duitku_reconcile_admit_lookup('), 'Admin route must use the tested admission helper.');

$lockSelect = strpos($source, "SELECT pt.id,pt.gateway,pt.merchant_order_id,pt.amount,pt.status AS payment_status");
$lockTarget = strpos($source, "WHERE pt.id=:id");
$lock = ($lockSelect !== false && $lockTarget !== false && strpos($source, "FOR UPDATE", $lockTarget) !== false)
    ? $lockSelect
    : false;
$lockedOrderStates = strpos($source, "o.payment_status AS order_payment_status,o.order_status");
$recent = strpos($source, "SELECT id FROM audit_logs");
$started = strpos($source, "'outcome' => 'STARTED'");
$commit = strpos($source, '$pdo->commit();');
$admitCall = strpos($route, 'duitku_reconcile_admit_lookup(');
$network = strpos($route, 'duitku_query_transaction_status(');
reconcile_cooldown_assert(
    $lock !== false && $lockedOrderStates !== false && $recent !== false && $started !== false && $commit !== false && $admitCall !== false && $network !== false,
    'Expected payment/order row lock, cooldown query, STARTED marker, commit, and gateway request are required.'
);
reconcile_cooldown_assert(
    $lock < $lockedOrderStates && $lockedOrderStates < $recent && $recent < $started && $started < $commit && $admitCall < $network,
    'Admission helper must lock, check cooldown, persist STARTED and commit; route must admit before network I/O.'
);

$guard = strpos($route, 'if ($payment && !$cooldownRejected)');
reconcile_cooldown_assert($guard !== false, 'Cooldown-denied attempts must not write another lookup audit event.');
$rejectFlag = strpos($route, '$cooldownRejected = true;');
reconcile_cooldown_assert($rejectFlag !== false && $rejectFlag < $guard, 'Cooldown rejection must set the guard before generic error auditing.');
reconcile_cooldown_assert(
    strpos($source, "INTERVAL 60 SECOND") !== false,
    'The configured 60-second cooldown check must remain present.'
);

// Keep the HTTP route's security boundary and read-only nature explicit.
// These source guards complement (but do not replace) authenticated HTTP tests.
reconcile_cooldown_assert(
    strpos($route, 'admin_csrf_valid($_POST[\'csrf\'] ?? null)') !== false,
    'Admin reconciliation POST must validate its CSRF token.'
);
reconcile_cooldown_assert(
    strpos($route, 'if (!admin_can_manage_settings($user))') !== false,
    'Privileged admin authorization must remain enforced before reconciliation.'
);
foreach (['UPDATE payment_transactions', 'UPDATE orders', 'fulfillment_process(', 'checkout_create_payment('] as $forbiddenMutation) {
    reconcile_cooldown_assert(
        stripos($route, $forbiddenMutation) === false,
        'Read-only reconciliation route must not mutate payment/order state or start fulfillment/checkout.'
    );
}

echo "Admin Duitku cooldown structural regression checks passed.\n";
