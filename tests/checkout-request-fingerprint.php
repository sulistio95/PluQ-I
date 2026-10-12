<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/order_checkout.php';

$first = checkout_request_fingerprint('Player@example.com', 'mobile-legend', 42, [
    'zone_id' => ' 1234 ',
    'user_id' => ' 987654 ',
]);
$reordered = checkout_request_fingerprint('player@example.com', ' mobile-legend ', 42, [
    'user_id' => '987654',
    'zone_id' => '1234',
]);
if (!hash_equals($first, $reordered)) {
    fwrite(STDERR, "Equivalent normalized checkout requests should have the same fingerprint.\n");
    exit(1);
}

$changedProduct = checkout_request_fingerprint('player@example.com', 'mobile-legend', 43, [
    'user_id' => '987654',
    'zone_id' => '1234',
]);
$changedAccount = checkout_request_fingerprint('player@example.com', 'mobile-legend', 42, [
    'user_id' => '987655',
    'zone_id' => '1234',
]);
if (hash_equals($first, $changedProduct) || hash_equals($first, $changedAccount)) {
    fwrite(STDERR, "A different product or account must produce a different fingerprint.\n");
    exit(1);
}

echo "Checkout request fingerprint tests passed.\n";
