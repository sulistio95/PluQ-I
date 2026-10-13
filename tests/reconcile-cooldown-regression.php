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

$lock = strpos($source, "SELECT id FROM payment_transactions WHERE id=:id FOR UPDATE");
$recent = strpos($source, "SELECT id FROM audit_logs");
$started = strpos($source, "'outcome' => 'STARTED'");
$commit = strpos($source, '$pdo->commit();');
$network = strpos($route, 'duitku_query_transaction_status(');
reconcile_cooldown_assert(
    $lock !== false && $recent !== false && $started !== false && $commit !== false && $network !== false,
    'Expected row lock, cooldown query, STARTED marker, commit, and gateway request are required.'
);
reconcile_cooldown_assert(
    $lock < $recent && $recent < $started && $started < $commit && $commit < $network,
    'Admission must lock, check cooldown, persist STARTED and commit before network I/O.'
);

$guard = strpos($route, 'if ($payment && !$cooldownRejected)');
reconcile_cooldown_assert($guard !== false, 'Cooldown-denied attempts must not write another lookup audit event.');
$rejectFlag = strpos($route, '$cooldownRejected = true;');
reconcile_cooldown_assert($rejectFlag !== false && $rejectFlag < $guard, 'Cooldown rejection must set the guard before generic error auditing.');
reconcile_cooldown_assert(
    strpos($source, "INTERVAL 60 SECOND") !== false,
    'The configured 60-second cooldown check must remain present.'
);

echo "Admin Duitku cooldown structural regression checks passed.\n";
