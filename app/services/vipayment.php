<?php
declare(strict_types=1);

/** VIPayment (vip-reseller.co.id) form-encoded API helpers. */
function vipayment_config(): array
{
    $apiId = trim((string) getenv('VIPAYMENT_API_ID'));
    $apiKey = trim((string) getenv('VIPAYMENT_API_KEY'));
    $baseUrl = rtrim((string) (getenv('VIPAYMENT_API_URL') ?: 'https://vip-reseller.co.id/api'), '/');
    if ($apiId === '' || $apiKey === '') {
        throw new RuntimeException('VIPayment credentials are not configured.');
    }
    $parts = parse_url($baseUrl);
    if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        throw new InvalidArgumentException('VIPayment API URL must use HTTPS.');
    }
    return ['api_id' => $apiId, 'api_key' => $apiKey, 'base_url' => $baseUrl];
}

function vipayment_post(string $path, array $parameters = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required.');
    }
    $cfg = vipayment_config();
    if (!in_array($path, ['/profile', '/game-feature'], true)) {
        throw new InvalidArgumentException('Unsupported VIPayment API path.');
    }
    $payload = array_merge([
        'key' => $cfg['api_key'],
        'sign' => md5($cfg['api_id'] . $cfg['api_key']),
    ], $parameters);
    $handle = curl_init($cfg['base_url'] . $path);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize VIPayment request.');
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $failed = $response === false || curl_errno($handle) !== 0;
    curl_close($handle);
    if ($failed) {
        throw new RuntimeException('VIPayment network request failed.');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('VIPayment returned HTTP ' . $status . '.');
    }
    try {
        $decoded = json_decode((string) $response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('VIPayment returned invalid JSON.', 0, $e);
    }
    if (!is_array($decoded) || !array_key_exists('result', $decoded)) {
        throw new RuntimeException('VIPayment response has an unexpected structure.');
    }
    if ($decoded['result'] !== true) {
        throw new RuntimeException('VIPayment rejected the request: ' . substr((string) ($decoded['message'] ?? 'unknown error'), 0, 180));
    }
    return $decoded;
}

function vipayment_profile(): array
{
    $response = vipayment_post('/profile');
    if (!isset($response['data']['balance']) || !is_numeric($response['data']['balance'])) {
        throw new RuntimeException('VIPayment profile response has no numeric balance.');
    }
    return ['balance' => (float) $response['data']['balance'], 'data' => $response['data']];
}

function vipayment_game_services(?string $filterGame = null, ?string $filterStatus = 'available'): array
{
    $payload = ['type' => 'services'];
    if ($filterGame !== null && trim($filterGame) !== '') {
        $payload['filter_game'] = trim($filterGame);
    }
    if ($filterStatus !== null && in_array($filterStatus, ['available', 'empty'], true)) {
        $payload['filter_status'] = $filterStatus;
    }
    $response = vipayment_post('/game-feature', $payload);
    if (!isset($response['data']) || !is_array($response['data'])) {
        throw new RuntimeException('VIPayment services response has no service list.');
    }
    return $response['data'];
}
