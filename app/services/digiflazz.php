<?php
declare(strict_types=1);
require_once __DIR__ . '/provider_http.php';

function digiflazz_config(): array
{
    $username = trim((string) getenv('DIGIFLAZZ_USERNAME'));
    $apiKey = trim((string) getenv('DIGIFLAZZ_API_KEY'));
    if ($username === '' || $apiKey === '') {
        throw new RuntimeException('Digiflazz credentials are not configured.');
    }
    return [
        'username' => $username,
        'api_key' => $apiKey,
        'base_url' => rtrim((string) (getenv('DIGIFLAZZ_API_URL') ?: 'https://api.digiflazz.com/v1'), '/'),
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
    $data = $response['data'] ?? null;
    if (!is_array($data) || !isset($data['deposit'])) {
        throw new RuntimeException('Digiflazz balance response is missing the balance field.');
    }
    return ['balance' => (float) $data['deposit'], 'raw' => $response];
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
