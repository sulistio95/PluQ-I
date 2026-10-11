<?php
declare(strict_types=1);
require_once __DIR__ . '/provider_http.php';

function duitku_config(): array
{
    if (strtolower((string)(getenv('DUITKU_ENV') ?: 'sandbox')) !== 'sandbox') {
        throw new RuntimeException('Only sandbox payments are enabled in this build.');
    }
    $merchant = trim((string)getenv('DUITKU_MERCHANT_CODE'));
    $key = trim((string)getenv('DUITKU_API_KEY'));
    $method = strtoupper(trim((string)getenv('DUITKU_PAYMENT_METHOD')));
    $callback = trim((string)getenv('DUITKU_CALLBACK_URL'));
    $return = trim((string)getenv('DUITKU_RETURN_URL'));
    if ($merchant === '' || $key === '' || $method === '') {
        throw new RuntimeException('Duitku sandbox credentials/payment method are not configured.');
    }
    foreach ([$callback, $return] as $url) {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new RuntimeException('Duitku callback and return URLs must use HTTPS.');
        }
    }
    return [
        'merchant_code' => $merchant,
        'api_key' => $key,
        'payment_method' => $method,
        'callback_url' => $callback,
        'return_url' => $return,
        'endpoint' => 'https://sandbox.duitku.com/webapi/api/merchant/v2/inquiry',
    ];
}

function duitku_create_payment(array $config, string $orderId, int $amount, string $product, string $email): array
{
    if ($amount < 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Invalid payment amount or email.');
    }
    $signature = hash_hmac('sha256', $config['merchant_code'] . $orderId . $amount, $config['api_key']);
    $payload = [
        'merchantCode' => $config['merchant_code'],
        'paymentAmount' => $amount,
        'paymentMethod' => $config['payment_method'],
        'merchantOrderId' => $orderId,
        'productDetails' => mb_substr($product, 0, 250, 'UTF-8'),
        'email' => $email,
        'callbackUrl' => $config['callback_url'],
        'returnUrl' => $config['return_url'],
        'signature' => $signature,
        'expiryPeriod' => 30,
    ];
    $response = provider_http_json($config['endpoint'], $payload, [], 20);
    $url = (string)($response['paymentUrl'] ?? '');
    $parts = parse_url($url);
    if ((string)($response['statusCode'] ?? '') !== '00' || !is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || !str_ends_with(strtolower((string)($parts['host'] ?? '')), 'duitku.com')) {
        throw new RuntimeException('Duitku did not return a valid payment URL.');
    }
    return ['payment_url' => $url, 'reference' => isset($response['reference']) ? (string)$response['reference'] : null];
}

function duitku_verify_callback(array $config, array $data): bool
{
    $merchant = (string)($data['merchantCode'] ?? '');
    $amount = (string)($data['amount'] ?? '');
    $orderId = (string)($data['merchantOrderId'] ?? '');
    $signature = (string)($data['signature'] ?? '');
    if ($merchant === '' || $amount === '' || $orderId === '' || $signature === ''
        || !ctype_digit($amount) || !hash_equals($config['merchant_code'], $merchant)) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $merchant . $amount . $orderId, $config['api_key']), $signature);
}
