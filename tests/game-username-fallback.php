<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/game_username_fallback.php';

function fallback_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

fallback_test(game_username_gopay_mapping('free-fire', 'Free Fire')['code'] === 'FREEFIRE', 'Free Fire should map to GoPay FREEFIRE');
fallback_test(game_username_gopay_mapping('honor-of-kings', 'Honor of Kings')['code'] === 'HOK', 'Honor of Kings should map to HOK');
fallback_test(game_username_gopay_mapping('pubg-mobile', 'PUBG Mobile')['code'] === 'PUBG_ID', 'PUBG Mobile should map to PUBG_ID');
fallback_test(game_username_gopay_mapping('unknown-title', 'Unknown Title') === null, 'Unknown games must not be sent to GoPay');

$captured = [];
$transport = static function (string $url, array $headers, string $body) use (&$captured): array {
    $captured = ['url' => $url, 'headers' => $headers, 'payload' => json_decode($body, true, 16, JSON_THROW_ON_ERROR)];
    return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => ['userAccount' => 'PlayerOne']], JSON_THROW_ON_ERROR)];
};
$result = game_username_gopay_validate('free-fire', 'Free Fire', '123456789', '', $transport);
fallback_test($result['status'] === 'valid' && $result['username'] === 'PlayerOne', 'Successful GoPay response should return verified nickname');
fallback_test($captured['url'] === 'https://gopay.co.id/games/v1/order/user-account', 'Requests must use the fixed GoPay HTTPS host');
fallback_test($captured['payload']['code'] === 'FREEFIRE', 'GoPay request must use game code, not supplier product code');
fallback_test($captured['payload']['data']['userId'] === '123456789', 'GoPay request should contain supplied user ID');
fallback_test($captured['payload']['data']['zoneId'] === '', 'Zone-less games should send an empty zone');

$genshinPayload = [];
$genshinTransport = static function (string $url, array $headers, string $body) use (&$genshinPayload): array {
    $genshinPayload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    return ['status' => 200, 'body' => json_encode(['message' => 'Success', 'data' => ['username' => 'Traveler']], JSON_THROW_ON_ERROR)];
};
game_username_gopay_validate('genshin-impact', 'Genshin Impact', '12345', 'Asia', $genshinTransport);
fallback_test($genshinPayload['data']['zoneId'] === 'os_asia', 'Genshin Asia region should map to os_asia');

$invalidTransport = static fn(string $url, array $headers, string $body): array => [
    'status' => 200,
    'body' => json_encode(['success' => false, 'message' => 'User account not found'], JSON_THROW_ON_ERROR),
];
$invalidThrown = false;
try {
    game_username_gopay_validate('free-fire', 'Free Fire', 'bad-id', '', $invalidTransport);
} catch (GameUsernameInvalidException) {
    $invalidThrown = true;
}
fallback_test($invalidThrown, 'Explicit account-not-found response should be classified as invalid');

$ambiguousTransport = static fn(string $url, array $headers, string $body): array => [
    'status' => 200,
    'body' => json_encode(['success' => false, 'message' => 'Service temporarily busy'], JSON_THROW_ON_ERROR),
];
$unavailableThrown = false;
try {
    game_username_gopay_validate('free-fire', 'Free Fire', '123456789', '', $ambiguousTransport);
} catch (RuntimeException $e) {
    $unavailableThrown = true;
}
fallback_test($unavailableThrown, 'Ambiguous provider response must be unavailable, not invalid');

$zoneUnsupported = game_username_gopay_validate('genshin-impact', 'Genshin Impact', '12345', 'unknown-region', $transport);
fallback_test($zoneUnsupported['status'] === 'unsupported', 'Unknown Genshin regions must not be guessed');

echo "Game username fallback adapter tests passed.\n";
