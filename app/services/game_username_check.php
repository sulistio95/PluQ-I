<?php
declare(strict_types=1);

require_once __DIR__ . '/vipayment.php';

/** Rate-limit rejection is intentionally distinguishable from provider failures. */
final class GameUsernameRateLimitException extends RuntimeException
{
}

final class GameUsernameInvalidException extends DomainException
{
}

/** Build the documented VIPayment Game Feature nickname lookup parameters. */
function game_username_check_parameters(string $gameCode, string $userId, string $zone = ''): array
{
    $supported = [
        'mobile-legends',
        'mobile-legends-region',
        'free-fire',
        'pubgm',
        'valorant',
        'genshin-impact',
        'honkai-star-rail',
        'pointblank',
    ];
    if (!in_array($gameCode, $supported, true)) {
        throw new InvalidArgumentException('Game username check is not supported for this title.');
    }
    $userId = trim($userId);
    $zone = trim($zone);
    if ($userId === '' || strlen($userId) > 190 || strlen($zone) > 190) {
        throw new InvalidArgumentException('Invalid account input for nickname lookup.');
    }

    return [
        'type' => 'get-nickname',
        'code' => $gameCode,
        'target' => $userId,
        'additional_target' => $zone,
    ];
}

/** Call VIPayment Get Nickname using the existing API credentials from admin settings. */
function game_username_check(string $gameCode, string $userId, string $zone = ''): string
{
    $parameters = game_username_check_parameters($gameCode, $userId, $zone);
    try {
        $response = vipayment_post('/game-feature', $parameters);
    } catch (RuntimeException $e) {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'vipayment rejected the request')
            && preg_match('/(?:target|user.?id|account|zone).*(?:not found|invalid|wrong|tidak ditemukan|tidak valid|salah)|(?:not found|invalid|wrong|tidak ditemukan|tidak valid|salah).*(?:target|user.?id|account|zone)/i', $message) === 1) {
            throw new GameUsernameInvalidException('VIPayment reports that the game account was not found.', 0, $e);
        }
        throw $e;
    }

    $username = trim((string) ($response['data'] ?? ''));
    if ($username === '') {
        throw new RuntimeException('VIPayment nickname response did not include a username.');
    }
    return $username;
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
