<?php
declare(strict_types=1);

/** Source-level regression guard: late payment success must be recorded but never auto-fulfilled. */
$path = dirname(__DIR__) . '/public/duitku-callback.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read Duitku callback handler.\n");
    exit(1);
}
$checks = [
    'late success is limited to a previously failed payment transaction' =>
        str_contains($source, "if (\$tx['status']==='FAILED' && \$status==='PAID')"),
    'late success changes the payment transaction from FAILED to PAID conditionally' =>
        str_contains($source, "SET status='PAID'") && str_contains($source, "WHERE id=:id AND status='FAILED'"),
    'late success records the payment as PAID but order remains MANUAL_REVIEW' =>
        str_contains($source, "payment_status='PAID'") && str_contains($source, "order_status='MANUAL_REVIEW'") &&
        str_contains($source, 'Late successful Duitku callback after a recorded failure'),
    'terminal callback conflict remains auditable' =>
        str_contains($source, 'DUITKU_TERMINAL_CALLBACK_CONFLICT'),
    'late-success branch returns through the terminal-callback path before normal fulfillment admission' =>
        str_contains($source, 'late_success_manual_review') && str_contains($source, "json_response(['status'=>'ok']);"),
];
$failed = [];
foreach ($checks as $name => $passed) {
    printf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $name);
    if (!$passed) $failed[] = $name;
}
if ($failed !== []) {
    fwrite(STDERR, count($failed) . " late-success regression check(s) failed.\n");
    exit(1);
}
printf("All %d late-success regression checks passed.\n", count($checks));
