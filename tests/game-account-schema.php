<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/game_fields.php';

function schema_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$mobileLegends = game_account_fields_for_game('mobile-legends', 'Mobile Legends');
schema_test(array_column($mobileLegends, 'field_key') === ['user_id', 'zone_id'], 'Mobile Legends should request User ID and Zone ID');

$honorOfKings = game_account_fields_for_game('honor-of-kings', 'Honor of Kings');
schema_test(array_column($honorOfKings, 'field_key') === ['user_id'], 'Honor of Kings should request only User ID');

$serverTitle = game_account_fields_for_game('example-region-game', 'Example Region Game');
schema_test(array_column($serverTitle, 'field_key') === ['user_id', 'server'], 'Titles whose metadata names a server/region should show a server field');

$unknown = game_account_fields_for_game('unknown-game', 'Unknown Game');
schema_test(array_column($unknown, 'field_key') === ['user_id'], 'Unknown games should use the safe User ID fallback');
schema_test($unknown[0]['is_required'] === true, 'User ID must be required');
schema_test(preg_match('~^(?:' . $unknown[0]['validation_regex'] . ')$~uD', 'Player123') === 1, 'Generated User ID regex should accept ordinary IDs');
schema_test(preg_match('~^(?:' . $unknown[0]['validation_regex'] . ')$~uD', 'Player 123') !== 1, 'Generated User ID regex should reject whitespace');

echo "Automatic game account schema tests passed.\n";
