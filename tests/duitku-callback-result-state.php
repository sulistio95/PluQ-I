<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/duitku.php';

$cases = [
    '00' => 'PAID',
    '01' => 'FAILED',
];
foreach ($cases as $code => $expected) {
    $actual = duitku_callback_result_state($code);
    if ($actual !== $expected) {
        fwrite(STDERR, "Expected {$code} => {$expected}, got {$actual}.\n");
        exit(1);
    }
}

try {
    duitku_callback_result_state('99');
    fwrite(STDERR, "Unsupported result code should throw.\n");
    exit(1);
} catch (InvalidArgumentException $expected) {
    // Expected: only documented success/failure callback states are mapped here.
}

$config = ['merchant_code' => 'DTEST', 'api_key' => 'test-only-secret'];
$orderId = str_repeat('a', 26);
$data = [
    'merchantCode' => 'DTEST',
    'amount' => '2500',
    'merchantOrderId' => $orderId,
    'signature' => hash_hmac('sha256', 'DTEST2500' . $orderId, 'test-only-secret'),
];
if (!duitku_verify_callback($config, $data)) {
    fwrite(STDERR, "A correctly signed callback should verify.\n");
    exit(1);
}
$badAmount = $data;
$badAmount['amount'] = '2501';
if (duitku_verify_callback($config, $badAmount)) {
    fwrite(STDERR, "A callback with a modified amount must fail signature verification.\n");
    exit(1);
}
$badSignature = $data;
$badSignature['signature'] = str_repeat('0', 64);
if (duitku_verify_callback($config, $badSignature)) {
    fwrite(STDERR, "An invalid signature must be rejected.\n");
    exit(1);
}

echo "Duitku callback result-state and signature tests passed.\n";
