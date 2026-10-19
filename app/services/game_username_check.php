<?php
declare(strict_types=1);

/**
 * Dedicated third-party nickname validation service.
 * This service is separate from payment and top-up supplier adapters.
 */
function game_username_checker_config(): array
{
    $apiKey = trim((string) (getenv('GAME_CHECK_API_KEY') ?: ''));
    if ($apiKey === '') {
        throw new RuntimeException('Game username checker API key is not configured.');
    }

    return [
        'base_url' => 'https://gamecheck.evogamestore.com',
        'api_key' => $apiKey,
    ];
}

/** Call the public Game Check API using only the supported game-code allowlist. */
function game_username_check(string $gameCode, string $userId, string $zone = ''): string
{
    $supported = [
        'mobile-legends',
        'mobile-legends-region',
        'free-fire',
        'pubg-mobile',
        'valorant',
        'genshin-impact',
        'honkai-star-rail',
        'point-blank',
    ];
    if (!in_array($gameCode, $supported, true)) {
        throw new InvalidArgumentException('Game username check is not supported for this title.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for username checking.');
    }

    $config = game_username_checker_config();
    $payload = ['game' => $gameCode, 'user_id' => $userId];
    if ($zone !== '') {
        $payload['zone'] = $zone;
    }

    $handle = curl_init($config['base_url'] . '/api/v1/check');
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize game username check.');
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-Key: ' . $config['api_key'],
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $failed = $response === false || curl_errno($handle) !== 0;
    curl_close($handle);

    if ($failed) {
        throw new RuntimeException('Game username checker network request failed.');
    }
    if ($status === 404) {
        throw new DomainException('Game account ID or zone was not found.');
    }
    if ($status === 429) {
        throw new RuntimeException('Game username checker rate limit reached.');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Game username checker returned HTTP ' . $status . '.');
    }

    try {
        $decoded = json_decode((string) $response, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Game username checker returned invalid JSON.', 0, $e);
    }
    $username = trim((string) ($decoded['data']['username'] ?? ''));
    if (($decoded['status'] ?? '') !== 'success' || $username === '') {
        if (($decoded['status'] ?? '') === 'not_found') {
            throw new DomainException('Game account ID or zone was not found.');
        }
        throw new RuntimeException('Game username checker could not verify this account.');
    }

    return $username;
}
