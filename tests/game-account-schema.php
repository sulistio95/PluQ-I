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

$genshin = game_account_fields_for_game('genshin-impact', 'Genshin Impact');
schema_test(array_column($genshin, 'field_key') === ['user_id', 'zone_id'], 'Genshin Impact should request User ID and Zone/Server');
$honkaiStarRail = game_account_fields_for_game('honkai-star-rail', 'Honkai Star Rail');
schema_test(array_column($honkaiStarRail, 'field_key') === ['user_id', 'zone_id'], 'Honkai Star Rail should request User ID and Zone/Server');

schema_test(game_account_username_check_supported('free-fire', 'Free Fire'), 'Free Fire nickname lookup should use VIPayment dedicated code');
schema_test(game_account_username_check_code('free-fire', 'Free Fire') === 'free-fire', 'Free Fire lookup must use free-fire, not a top-up product code');
schema_test(game_account_username_check_code('free-fire-max', 'Free Fire MAX') === 'free-fire', 'Free Fire MAX should use the documented free-fire lookup code');
schema_test(game_account_username_check_code('mobile-legends', 'Mobile Legends') === 'mobile-legends', 'Mobile Legends should use its dedicated lookup code');
schema_test(game_account_username_check_code('mobile-legends-region', 'Mobile Legends Region') === 'mobile-legends-region', 'Mobile Legends Region should use its dedicated lookup code');
schema_test(game_account_username_check_code('pubgm', 'PUBG Mobile') === 'pubgm', 'PUBG Mobile should use its dedicated lookup code');
schema_test(game_account_username_check_code('valorant', 'Valorant') === 'valorant', 'Valorant should use its dedicated lookup code');
schema_test(game_account_username_check_code('genshin-impact', 'Genshin Impact') === 'genshin-impact', 'Genshin Impact should use its dedicated lookup code');
schema_test(game_account_username_check_code('honkai-star-rail', 'Honkai Star Rail') === 'honkai-star-rail', 'Honkai Star Rail should use its dedicated lookup code');
schema_test(game_account_username_check_code('pointblank', 'Point Blank') === 'pointblank', 'Point Blank should use its dedicated lookup code');
schema_test(!game_account_username_check_supported('honor-of-kings', 'Honor of Kings'), 'Honor of Kings should remain unsupported because it is absent from the supplied lookup list');

$honorOfKings = game_account_fields_for_game('honor-of-kings', 'Honor of Kings');
schema_test(array_column($honorOfKings, 'field_key') === ['user_id'], 'Honor of Kings should request only User ID');
schema_test(game_account_schema_is_supported('honor-of-kings', 'Honor of Kings', $honorOfKings), 'Reviewed Honor of Kings schema should allow checkout');

$serverTitle = game_account_fields_for_game('example-region-game', 'Example Region Game');
schema_test(array_column($serverTitle, 'field_key') === ['user_id', 'server'], 'Titles whose metadata names a server/region should show a server field');
schema_test(!game_account_schema_is_supported('example-region-game', 'Example Region Game', $serverTitle), 'Unmapped server fields must block checkout');
schema_test(preg_match('~^(?:' . $serverTitle[1]['validation_regex'] . ')$~uD', 'North America') === 1, 'Server names may contain spaces');

$unknown = game_account_fields_for_game('unknown-game', 'Unknown Game');
schema_test(array_column($unknown, 'field_key') === ['user_id'], 'Unknown games should use the safe User ID fallback');
schema_test(!game_account_schema_is_supported('unknown-game', 'Unknown Game', $unknown), 'Unknown game schemas must block checkout');
schema_test($unknown[0]['is_required'] === true, 'User ID must be required');
schema_test(preg_match('~^(?:' . $unknown[0]['validation_regex'] . ')$~uD', 'Player123') === 1, 'Generated User ID regex should accept ordinary IDs');
schema_test(preg_match('~^(?:' . $unknown[0]['validation_regex'] . ')$~uD', 'Player 123') !== 1, 'Generated User ID regex should reject whitespace');

echo "Automatic game account schema tests passed.\n";
