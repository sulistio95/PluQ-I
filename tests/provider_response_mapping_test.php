<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/fulfillment.php';

function provider_map_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$digiflazzCases = [
    ['Sukses', 'SUCCESS'],
    ['Pending', 'PENDING'],
    ['Gagal', 'FAILED'],
    ['unexpected-provider-state', 'REVIEW'],
];
foreach ($digiflazzCases as [$status, $expected]) {
    $mapped = fulfillment_map_digiflazz_response(
        ['data' => ['status' => $status, 'sn' => 'SN-123']],
        'PQI-REF-1'
    );
    provider_map_assert_same($expected, $mapped['state'], 'Digiflazz status mapping failed for ' . $status);
}
provider_map_assert_same(
    'PQI-REF-1',
    fulfillment_map_digiflazz_response(['data' => ['status' => 'Sukses']], 'PQI-REF-1')['reference'],
    'Digiflazz must use the stable local reference when no serial number is returned.'
);

$vipOrderCases = [
    ['waiting', 'PENDING'],
    ['processing', 'PENDING'],
    ['success', 'SUCCESS'],
    ['error', 'FAILED'],
    ['unknown', 'REVIEW'],
];
foreach ($vipOrderCases as [$status, $expected]) {
    $mapped = fulfillment_map_vipayment_response(
        ['data' => ['trxid' => 'VP123', 'status' => $status]]
    );
    provider_map_assert_same($expected, $mapped['state'], 'VIPayment order status mapping failed for ' . $status);
    provider_map_assert_same('VP123', $mapped['reference'], 'VIPayment must retain the provider transaction ID.');
}

$vipStatus = fulfillment_map_vipayment_response(
    ['data' => [['trxid' => 'VP123', 'status' => 'success']]],
    'VP123'
);
provider_map_assert_same('SUCCESS', $vipStatus['state'], 'VIPayment status-list response should map success.');
provider_map_assert_same(
    'REVIEW',
    fulfillment_map_vipayment_response(['data' => []], 'VP123')['state'],
    'Missing VIPayment status data must require manual review.'
);

$failed = false;
try {
    fulfillment_map_digiflazz_response(['data' => []], 'PQI-REF-1');
} catch (RuntimeException $e) {
    $failed = true;
}
provider_map_assert_same(true, $failed, 'Malformed Digiflazz responses must be rejected.');

echo "Supplier response mapping tests passed.\\n";
