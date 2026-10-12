<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/fulfillment.php';

function expect_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$account = fulfillment_account(['user_id' => ' 123456 ', 'zone_id' => ' 7 ']);
expect_same(['user' => '123456', 'zone' => '7'], $account, 'Normalize game account');

$accountNoZone = fulfillment_account(['data_no' => '998877']);
expect_same(['user' => '998877', 'zone' => ''], $accountNoZone, 'Allow games without zone');

foreach ([
    ['user_id' => '1', 'player_id' => '2'],
    ['user_id' => '1', 'unexpected_field' => 'x'],
    ['zone_id' => '2'],
] as $invalidAccount) {
    try {
        fulfillment_account($invalidAccount);
        fwrite(STDERR, "Invalid or ambiguous account mapping should be rejected.\n");
        exit(1);
    } catch (DomainException $expected) {
        // Expected fail-closed behavior.
    }
}

expect_same('SUCCESS', fulfillment_map_digiflazz_response(['data' => ['status' => 'Sukses']], 'ref-1')['state'], 'Digiflazz success');
expect_same('PENDING', fulfillment_map_digiflazz_response(['data' => ['status' => 'Pending']], 'ref-1')['state'], 'Digiflazz pending');
expect_same('FAILED', fulfillment_map_digiflazz_response(['data' => ['status' => 'Gagal']], 'ref-1')['state'], 'Digiflazz explicit failure');
expect_same('REVIEW', fulfillment_map_digiflazz_response(['data' => ['status' => 'unknown']], 'ref-1')['state'], 'Unknown Digiflazz state');

$vipSuccess = ['data' => ['trxid' => 'VX-123', 'status' => 'success']];
expect_same('SUCCESS', fulfillment_map_vipayment_response($vipSuccess)['state'], 'VIPayment success');
expect_same('REVIEW', fulfillment_map_vipayment_response(
    ['data' => [['trxid' => 'VX-OTHER', 'status' => 'success']]],
    'VX-123'
)['state'], 'VIPayment status must match requested transaction');

echo "Supplier account and response mapping tests passed.\n";
