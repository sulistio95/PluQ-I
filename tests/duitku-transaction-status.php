<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/duitku.php';

function status_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$config = ['merchant_code' => 'DTEST', 'api_key' => 'test-only-secret'];
$orderId = str_repeat('a', 26);
$expectedSignature = hash_hmac('sha256', 'DTEST' . $orderId, 'test-only-secret');
status_test_assert(duitku_transaction_status_signature($config, $orderId) === $expectedSignature, 'Status query signature must use HMAC-SHA256 over merchant code + order ID.');

$cases = ['00' => 'SUCCESS', '01' => 'PROCESS', '02' => 'FAILED_OR_EXPIRED'];
foreach ($cases as $code => $state) {
    status_test_assert(duitku_transaction_status_state($code) === $state, "Unexpected status mapping for {$code}.");
}
try {
    duitku_transaction_status_state('99');
    status_test_assert(false, 'Unknown status code must be rejected.');
} catch (InvalidArgumentException $expected) {
}

$response = [
    'merchantOrderId' => $orderId,
    'amount' => '2500',
    'reference' => 'D-TEST-REFERENCE',
    'statusCode' => '01',
    'statusMessage' => 'PROCESS',
];
$validated = duitku_validate_transaction_status_response($response, $orderId, 2500);
status_test_assert($validated['state'] === 'PROCESS', 'Status 01 must remain processing, not failed.');
status_test_assert($validated['reference'] === 'D-TEST-REFERENCE', 'Gateway reference should be preserved.');
foreach ([
    [['merchantOrderId' => str_repeat('b', 26)] + array_diff_key($response, ['merchantOrderId' => true]), 'order mismatch'],
    [array_merge($response, ['amount' => '2501']), 'amount mismatch'],
    [array_merge($response, ['statusCode' => '99']), 'unknown status'],
    [array_merge($response, ['amount' => '2500.00']), 'non-integer amount'],
    [array_merge($response, ['reference' => []]), 'invalid reference'],
] as [$badResponse, $label]) {
    try {
        duitku_validate_transaction_status_response($badResponse, $orderId, 2500);
        status_test_assert(false, "Must reject {$label}.");
    } catch (InvalidArgumentException|UnexpectedValueException $expected) {
    }
}

try {
    duitku_transaction_status_signature(['merchant_code' => 'DTEST', 'api_key' => ''], $orderId);
    status_test_assert(false, 'Missing API key must be rejected.');
} catch (InvalidArgumentException $expected) {
}

echo "Duitku transaction-status signature, status mapping, and response validation tests passed.\n";
