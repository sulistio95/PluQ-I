<?php
declare(strict_types=1);

/**
 * Minimal, isolated GoPay Games fallback adapter inspired by the public
 * triyatna/php-valid-game provider mapping. This does not create top-up orders.
 */
final class GameUsernameInvalidException extends DomainException
{
}

/** @return array{code:string,zone_required:bool}|null */
function game_username_gopay_mapping(string $slug, string $name): ?array
{
    $identity = strtolower(trim($slug . ' ' . $name));
    $mappings = [
        '/mobile[\s_-]*legends?|\bmlbb\b/' => ['MOBILE_LEGENDS', true],
        '/free[\s_-]*fire(?:[\s_-]*max)?/' => ['FREEFIRE', false],
        '/genshin[\s_-]*impact/' => ['GENSHIN_IMPACT', true],
        '/valorant/' => ['VALORANT', false],
        '/honor[\s_-]*of[\s_-]*kings?|\bhok\b/' => ['HOK', false],
        '/pubg[\s_-]*mobile|\bpubgm\b/' => ['PUBG_ID', false],
        '/fc[\s_-]*mobile/' => ['FC_MOBILE', false],
        '/magic[\s_-]*chess.*go[\s_-]*go|\bmcgg\b/' => ['MAGIC_CHESS_GO_GO', true],
        '/arena[\s_-]*of[\s_-]*valor|\baov\b/' => ['AOV', false],
        '/call[\s_-]*of[\s_-]*duty|\bcodm?\b/' => ['CALL_OF_DUTY', false],
    ];
    foreach ($mappings as $pattern => [$code, $zoneRequired]) {
        if (preg_match($pattern, $identity) === 1) {
            return ['code' => $code, 'zone_required' => $zoneRequired];
        }
    }
    return null;
}

/**
 * @param callable(string,array<string,string>,string):array{status:int,body:string}|null $transport
 * @return array{status:string,username?:string,provider:string}
 */
function game_username_gopay_validate(
    string $slug,
    string $name,
    string $userId,
    string $zone = '',
    ?callable $transport = null
): array {
    $mapping = game_username_gopay_mapping($slug, $name);
    if ($mapping === null) {
        return ['status' => 'unsupported', 'provider' => 'gopay-games'];
    }
    if ($userId === '' || strlen($userId) > 190 || strlen($zone) > 190) {
        throw new InvalidArgumentException('Invalid account input for nickname lookup.');
    }
    if ($mapping['zone_required'] && $zone === '') {
        throw new InvalidArgumentException('A server or zone is required for this game.');
    }

    $zoneId = $zone;
    if ($mapping['code'] === 'GENSHIN_IMPACT') {
        $zones = [
            'asia' => 'os_asia', 'os_asia' => 'os_asia',
            'america' => 'os_usa', 'usa' => 'os_usa', 'os_usa' => 'os_usa',
            'europe' => 'os_euro', 'eu' => 'os_euro', 'os_euro' => 'os_euro',
            'taiwan' => 'os_cht', 'cht' => 'os_cht', 'os_cht' => 'os_cht',
        ];
        $zoneKey = strtolower(trim($zone));
        if (!isset($zones[$zoneKey])) {
            return ['status' => 'unsupported', 'provider' => 'gopay-games'];
        }
        $zoneId = $zones[$zoneKey];
    }

    $payload = [
        'code' => $mapping['code'],
        'data' => [
            'userId' => $userId,
            'zoneId' => $zoneId,
        ],
    ];
    $url = 'https://gopay.co.id/games/v1/order/user-account';
    if ($transport !== null) {
        $response = $transport($url, ['Content-Type' => 'application/json', 'Accept' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
    } else {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required for GoPay Games fallback.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize GoPay Games fallback.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: Mozilla/5.0 (compatible; PluQ-I game-check/1.0)',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failed = $body === false || curl_errno($handle) !== 0;
        curl_close($handle);
        if ($failed) {
            throw new RuntimeException('GoPay Games fallback network request failed.');
        }
        $response = ['status' => $statusCode, 'body' => (string) $body];
    }

    $httpStatus = (int) ($response['status'] ?? 0);
    $body = (string) ($response['body'] ?? '');
    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException('GoPay Games fallback returned HTTP ' . $httpStatus . '.');
    }
    try {
        $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('GoPay Games fallback returned invalid JSON.', 0, $e);
    }
    if (!is_array($data)) {
        throw new RuntimeException('GoPay Games fallback returned an unexpected response.');
    }

    $username = '';
    foreach ([
        $data['data']['username'] ?? null,
        $data['data']['userAccount'] ?? null,
        $data['data']['nickname'] ?? null,
        $data['data']['name'] ?? null,
        $data['username'] ?? null,
        $data['userAccount'] ?? null,
    ] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $username = trim($candidate);
            break;
        }
    }
    $success = (isset($data['message']) && strtolower((string) $data['message']) === 'success')
        || (($data['success'] ?? false) === true)
        || (($data['status'] ?? false) === true)
        || $username !== '';
    if ($success && $username !== '') {
        return ['status' => 'valid', 'username' => $username, 'provider' => 'gopay-games'];
    }
    if ($success) {
        // A positive response without a nickname is not strong enough to verify.
        throw new RuntimeException('GoPay Games fallback did not return a nickname.');
    }

    $message = '';
    foreach ([$data['message'] ?? null, $data['error'] ?? null, $data['data']['message'] ?? null, $data['errorMessage'] ?? null, $data['msg'] ?? null] as $candidate) {
        if (is_string($candidate) && trim($candidate) !== '') {
            $message = strtolower(trim($candidate));
            break;
        }
    }
    if ($message !== '' && preg_match('/(user|account|player|uid|id).*(not found|invalid|does not exist|不存在|tidak ditemukan|tidak valid)|(not found|invalid|does not exist|不存在|tidak ditemukan|tidak valid).*(user|account|player|uid|id)/i', $message) === 1) {
        throw new GameUsernameInvalidException('Game account ID or zone was not found.');
    }

    // Ambiguous provider errors must never be presented as a confirmed invalid ID.
    throw new RuntimeException('GoPay Games fallback could not verify this account.');
}

/** Try the primary Evo checker first; only transport/provider failures trigger fallback. */
function game_username_check_with_fallback(PDO $pdo, string $gameCode, string $userId, string $zone, string $gameSlug, string $gameName): string
{
    $primaryError = null;
    if ($gameCode !== '') {
        try {
            return game_username_check($gameCode, $userId, $zone);
        } catch (DomainException $e) {
            // A definitive invalid/not-found response from the primary provider is not retried.
            throw $e;
        } catch (RuntimeException $e) {
            $primaryError = $e;
        }
    }
    try {
        // GoPay-only titles need their own per-account cooldown because no Evo limit ran.
        if ($gameCode === '') {
            $accountKey = hash('sha256', $gameSlug . '|' . $userId . '|' . $zone);
            game_username_check_apply_limit($pdo, 'gopay-account:' . $accountKey, 1, 5);
        }
        // Separate global budget: fallback requests are counted independently from Evo.
        game_username_check_apply_limit($pdo, 'global-gopay-fallback', 25, 60);
        $fallback = game_username_gopay_validate($gameSlug, $gameName, $userId, $zone);
    } catch (GameUsernameInvalidException $e) {
        throw $e;
    } catch (GameUsernameRateLimitException $e) {
        throw $e;
    } catch (Throwable $e) {
        throw new RuntimeException('GoPay Games fallback is temporarily unavailable.', 0, $primaryError ?? $e);
    }
        if ($fallback['status'] === 'valid' && isset($fallback['username'])) {
            return $fallback['username'];
        }
        if ($fallback['status'] === 'unsupported') {
            throw new RuntimeException('No fallback provider is configured for this game.', 0, $primaryError);
        }
        throw new RuntimeException('Nickname providers are temporarily unavailable.', 0, $primaryError);
}
