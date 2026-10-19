<?php
declare(strict_types=1);
require_once __DIR__ . '/provider_http.php';
require_once __DIR__ . '/api_secret_crypto.php';

function digiflazz_config(): array
{
    $username = trim((string) pluqi_api_config_value('DIGIFLAZZ_USERNAME'));
    $apiKey = trim((string) pluqi_api_config_value('DIGIFLAZZ_API_KEY'));
    if ($username === '' || $apiKey === '') {
        throw new RuntimeException('Digiflazz credentials are not configured.');
    }
    return [
        'username' => $username,
        'api_key' => $apiKey,
        'base_url' => rtrim((string) (pluqi_api_config_value('DIGIFLAZZ_API_URL') ?: 'https://api.digiflazz.com/v1'), '/'),
    ];
}

function digiflazz_balance(): array
{
    $cfg = digiflazz_config();
    $response = provider_http_json($cfg['base_url'] . '/cek-saldo', [
        'cmd' => 'deposit',
        'username' => $cfg['username'],
        'sign' => md5($cfg['username'] . $cfg['api_key'] . 'depo'),
    ]);
    return digiflazz_map_balance_response($response);
}

/** Validate the supplier balance before converting it; malformed text must not silently become zero. */
function digiflazz_map_balance_response(array $response): array
{
    $data = $response['data'] ?? null;
    if (!is_array($data) || !array_key_exists('deposit', $data)
        || !is_numeric($data['deposit'])) {
        throw new RuntimeException('Digiflazz balance response has an invalid balance field.');
    }
    $balance = (float) $data['deposit'];
    if (!is_finite($balance) || $balance < 0) {
        throw new RuntimeException('Digiflazz balance response has an invalid balance field.');
    }
    return ['balance' => $balance, 'raw' => $response];
}

function digiflazz_price_list(?string $category = null): array
{
    $cfg = digiflazz_config();
    $payload = [
        'cmd' => 'prepaid',
        'username' => $cfg['username'],
        'sign' => md5($cfg['username'] . $cfg['api_key'] . 'pricelist'),
    ];
    if ($category !== null && trim($category) !== '') {
        $payload['category'] = trim($category);
    }
    $response = provider_http_json($cfg['base_url'] . '/price-list', $payload);
    if (!isset($response['data']) || !is_array($response['data'])) {
        throw new RuntimeException('Digiflazz price-list response is missing its data list.');
    }
    return $response['data'];
}
