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

function assert_rejected(array $input, string $message): void
{
    try {
        fulfillment_account($input);
    } catch (DomainException) {
        return;
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

assert_same(['user'=>'12345','zone'=>'9876'], fulfillment_account(['user_id'=>'12345','zone_id'=>'9876']), 'Maps game ID and zone ID.');
assert_same(['user'=>'12345','zone'=>''], fulfillment_account(['user_id'=>'12345']), 'Maps a single account ID.');
assert_same(['user'=>'12345','zone'=>'9876'], fulfillment_account(['server'=>'9876','player'=>'12345']), 'Maps player and server by key regardless of order.');
assert_same(['user'=>'12345','zone'=>'9876'], fulfillment_account(['zone_id'=>'9876','user_id'=>'12345']), 'Maps reversed input order safely.');

assert_rejected(['zone_id'=>'9876'], 'Rejects account data without a user ID.');
assert_rejected(['account'=>'12345','realm'=>'9876'], 'Rejects unknown account keys rather than guessing by array order.');
assert_rejected(['user_id'=>'12345','player_id'=>'54321'], 'Rejects conflicting user ID aliases.');
assert_rejected(['user_id'=>'12345','zone_id'=>'9876','server'=>'54321'], 'Rejects conflicting zone/server aliases.');

fwrite(STDOUT, "Fulfillment account mapping tests passed.\n");
