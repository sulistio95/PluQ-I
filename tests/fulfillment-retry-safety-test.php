<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/fulfillment.php';

function retry_safety_assert(bool $expected, bool $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

retry_safety_assert(
    true,
    fulfillment_supplier_state_requires_review(['status' => 'PROCESSING'], 'VIPAY'),
    'A stale PROCESSING supplier request must not be submitted again.'
);
retry_safety_assert(
    true,
    fulfillment_supplier_state_requires_review(['status' => 'RETRYING', 'provider_reference' => null], 'VIPAY'),
    'VIPayment RETRYING without trxid must go to manual review, not create a duplicate order.'
);
retry_safety_assert(
    true,
    fulfillment_supplier_state_requires_review(['status' => 'RETRYING', 'provider_reference' => ''], 'VIPAY'),
    'VIPayment RETRYING with an empty trxid must go to manual review.'
);
retry_safety_assert(
    false,
    fulfillment_supplier_state_requires_review(['status' => 'RETRYING', 'provider_reference' => 'VP-123'], 'VIPAY'),
    'VIPayment RETRYING with a known trxid may safely query that transaction.'
);
retry_safety_assert(
    true,
    fulfillment_supplier_state_requires_review(['status' => 'RETRYING', 'provider_reference' => 'DF-123'], 'DIGI'),
    'Digiflazz RETRYING remains fail-closed until retry semantics are verified.'
);
retry_safety_assert(
    false,
    fulfillment_supplier_state_requires_review(['status' => 'FAILED', 'provider_reference' => null], 'VIPAY'),
    'A definitive FAILED supplier record is not a retry ambiguity.'
);

echo "Supplier retry safety tests passed." . PHP_EOL;
