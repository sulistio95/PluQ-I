<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/fulfillment.php';

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

assert_same(['user'=>'12345','zone'=>'9876'], fulfillment_account(['user_id'=>'12345','zone_id'=>'9876']), 'Maps game ID and zone ID.');
assert_same(['user'=>'12345','zone'=>''], fulfillment_account(['user_id'=>'12345']), 'Maps a single account ID.');
assert_same(['user'=>'12345','zone'=>'9876'], fulfillment_account(['player'=>'12345','server'=>'9876']), 'Maps generic account and server fields.');

$failed = false;
try {
    fulfillment_account(['zone_id'=>'9876']);
} catch (DomainException $e) {
    $failed = true;
}
assert_same(true, $failed, 'Rejects account data without a user ID.');

fwrite(STDOUT, "Fulfillment account mapping tests passed.\n");
