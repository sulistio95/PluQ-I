<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/duitku.php';

function duitku_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = [
    'merchant_code' => 'DUMMYMERCHANT',
    'api_key' => 'dummy-sandbox-key',
];

$data = [
    'merchantCode' => 'DUMMYMERCHANT',
    'amount' => '125000',
    'merchantOrderId' => 'ORDERABC123',
];
$data['signature'] = hash_hmac(
    'sha256',
    $data['merchantCode'] . $data['amount'] . $data['merchantOrderId'],
    $config['api_key']
);

duitku_test_assert(duitku_verify_callback($config, $data), 'A valid callback signature should verify.');

$badSignature = $data;
$badSignature['signature'] = str_repeat('0', 64);
duitku_test_assert(!duitku_verify_callback($config, $badSignature), 'An incorrect signature must be rejected.');

$badAmount = $data;
$badAmount['amount'] = '125000.00';
duitku_test_assert(!duitku_verify_callback($config, $badAmount), 'A non-integer callback amount must be rejected.');

$badMerchant = $data;
$badMerchant['merchantCode'] = 'OTHERMERCHANT';
$badMerchant['signature'] = hash_hmac(
    'sha256',
    $badMerchant['merchantCode'] . $badMerchant['amount'] . $badMerchant['merchantOrderId'],
    $config['api_key']
);
duitku_test_assert(!duitku_verify_callback($config, $badMerchant), 'A different merchant code must be rejected.');

$missingOrder = $data;
unset($missingOrder['merchantOrderId']);
duitku_test_assert(!duitku_verify_callback($config, $missingOrder), 'A callback without an order ID must be rejected.');

echo "Duitku callback signature tests passed.\\n";
