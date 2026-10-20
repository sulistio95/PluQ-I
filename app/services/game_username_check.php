<?php
declare(strict_types=1);

/** Rate-limit rejection is intentionally distinguishable from provider failures. */
final class GameUsernameRateLimitException extends RuntimeException
{
}

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

/**
 * Atomically increment a fixed-window limit in MySQL. This works across PHP
 * sessions/processes and does not rely on Redis or local filesystem locks.
 */
function game_username_check_apply_limit(PDO $pdo, string $scope, int $maximum, int $windowSeconds): void
{
    $now = time();
    $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
    $key = hash('sha256', $scope . ':' . $windowStart);
    $stmt = $pdo->prepare(
        'INSERT INTO game_username_check_limits (limit_key, window_start, request_count)
         VALUES (:limit_key, :window_start, 1)
         ON DUPLICATE KEY UPDATE
           request_count = IF(window_start = VALUES(window_start), request_count + 1, 1),
           window_start = VALUES(window_start),
           updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute(['limit_key' => $key, 'window_start' => $windowStart]);
    $read = $pdo->prepare('SELECT request_count FROM game_username_check_limits WHERE limit_key = :limit_key LIMIT 1');
    $read->execute(['limit_key' => $key]);
    if ((int) $read->fetchColumn() > $maximum) {
        throw new GameUsernameRateLimitException('Nickname check rate limit reached.');
    }
}

/** Return a cached successful result; never cache invalid IDs or provider errors. */
function game_username_check_cached(PDO $pdo, string $fingerprint): ?string
{
    $stmt = $pdo->prepare(
        'SELECT username FROM game_username_check_cache
         WHERE fingerprint = :fingerprint AND expires_at > UTC_TIMESTAMP() LIMIT 1'
    );
    $stmt->execute(['fingerprint' => $fingerprint]);
    $username = $stmt->fetchColumn();
    return is_string($username) && trim($username) !== '' ? $username : null;
}

function game_username_check_cache_success(PDO $pdo, string $fingerprint, string $gameSlug, string $username): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO game_username_check_cache (fingerprint, game_slug, username, expires_at)
         VALUES (:fingerprint, :game_slug, :username, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 5 MINUTE))
         ON DUPLICATE KEY UPDATE game_slug = VALUES(game_slug), username = VALUES(username),
           expires_at = VALUES(expires_at), created_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'fingerprint' => $fingerprint,
        'game_slug' => $gameSlug,
        'username' => $username,
    ]);
}

/**
 * Guard provider calls across all customers. Per-IP limits protect the public
 * endpoint; a 50/minute global ceiling leaves headroom under the provider's
 * documented default rate limit. The caller should check cache before calling
 * this function so cache hits do not consume provider quota.
 */
function game_username_check_enforce_client_limit(PDO $pdo, string $clientIp): void
{
    $ipHash = hash('sha256', $clientIp !== '' ? $clientIp : 'unknown-client');
    game_username_check_apply_limit($pdo, 'ip:' . $ipHash, 20, 60);
}

/** Called only on cache misses, immediately before the external API request. */
function game_username_check_enforce_provider_limits(PDO $pdo, string $fingerprint): void
{
    game_username_check_apply_limit($pdo, 'fingerprint:' . $fingerprint, 1, 5);
    game_username_check_apply_limit($pdo, 'global-provider', 50, 60);
}

/** Call the Game Check API using only the supported game-code allowlist. */
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
        CURLOPT_TIMEOUT => 8,
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
        throw new GameUsernameRateLimitException('Game username checker rate limit reached.');
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
        if (($decoded['status'] ?? '') === 'temporary_failure') {
            throw new RuntimeException('Game username checker is temporarily unavailable.');
        }
        throw new RuntimeException('Game username checker could not verify this account.');
    }

    return $username;
}
