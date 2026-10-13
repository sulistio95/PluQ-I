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
        || !in_array(strtolower((string)($parts['host'] ?? '')), ['duitku.com'], true) && !str_ends_with(strtolower((string)($parts['host'] ?? '')), '.duitku.com')) {
        throw new RuntimeException('Duitku did not return a valid payment URL.');
    }
    return ['payment_url' => $url, 'reference' => isset($response['reference']) ? (string)$response['reference'] : null];
}

/** Map documented Duitku server-to-server callback result codes. */
function duitku_callback_result_state(string $resultCode): string
{
    return match ($resultCode) {
        '00' => 'PAID',
        '01' => 'FAILED',
        default => throw new InvalidArgumentException('Unsupported Duitku callback result code.'),
    };
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


/**
 * Build the current Duitku merchant transaction-status signature.
 * This is intentionally separate from callback signature verification.
 */
function duitku_transaction_status_signature(array $config, string $merchantOrderId): string
{
    $merchantCode = trim((string)($config['merchant_code'] ?? ''));
    $apiKey = (string)($config['api_key'] ?? '');
    if ($merchantCode === '' || $apiKey === '' || $merchantOrderId === '' || strlen($merchantOrderId) > 50) {
        throw new InvalidArgumentException('Duitku transaction-status request data is invalid.');
    }
    return hash_hmac('sha256', $merchantCode . $merchantOrderId, $apiKey);
}

/**
 * Status-query response codes differ from callback result codes:
 * 00=success, 01=still processing, 02=failed/expired.
 */
function duitku_transaction_status_state(string $statusCode): string
{
    return match ($statusCode) {
        '00' => 'SUCCESS',
        '01' => 'PROCESS',
        '02' => 'FAILED_OR_EXPIRED',
        default => throw new InvalidArgumentException('Unsupported Duitku transaction-status code.'),
    };
}

/**
 * Validate a status-query response against the expected merchant order and amount.
 * No database state is changed by this validation helper.
 */
function duitku_validate_transaction_status_response(array $response, string $expectedOrderId, int $expectedAmount): array
{
    $orderId = $response['merchantOrderId'] ?? null;
    $amount = $response['amount'] ?? null;
    $statusCode = $response['statusCode'] ?? null;
    if (!is_string($orderId) || $orderId === '' || !hash_equals($expectedOrderId, $orderId)) {
        throw new UnexpectedValueException('Duitku transaction-status order ID does not match.');
    }
    if ((!is_string($amount) && !is_int($amount)) || !ctype_digit((string)$amount)
        || $expectedAmount < 1) {
        throw new UnexpectedValueException('Duitku transaction-status amount does not match.');
    }
    // Compare decimal strings after removing leading zeroes; casting an arbitrarily
    // large digit string to int can saturate at PHP_INT_MAX and mis-validate it.
    $normalizedAmount = ltrim((string)$amount, '0');
    if ($normalizedAmount === '') $normalizedAmount = '0';
    if ($normalizedAmount !== (string)$expectedAmount) {
        throw new UnexpectedValueException('Duitku transaction-status amount does not match.');
    }
    if (!is_string($statusCode)) {
        throw new UnexpectedValueException('Duitku transaction-status response is incomplete.');
    }
    $state = duitku_transaction_status_state($statusCode);
    $reference = $response['reference'] ?? null;
    if ($reference !== null && !is_string($reference)) {
        throw new UnexpectedValueException('Duitku transaction-status reference is invalid.');
    }
    $message = $response['statusMessage'] ?? '';
    if (!is_string($message)) {
        throw new UnexpectedValueException('Duitku transaction-status message is invalid.');
    }
    return [
        'merchant_order_id' => $orderId,
        'amount' => (int)$amount,
        'state' => $state,
        'reference' => $reference,
        'status_message' => $message,
    ];
}




/** Minimal sandbox credentials for status lookup; payment-creation fields are not required. */
function duitku_status_config(): array
{
    if (strtolower((string)(getenv('DUITKU_ENV') ?: 'sandbox')) !== 'sandbox') {
        throw new RuntimeException('Only sandbox transaction-status lookups are enabled in this build.');
    }
    $merchant = trim((string)getenv('DUITKU_MERCHANT_CODE'));
    $key = trim((string)getenv('DUITKU_API_KEY'));
    if ($merchant === '' || $key === '') {
        throw new RuntimeException('Duitku sandbox merchant code and API key are required for status lookup.');
    }
    return [
        'merchant_code' => $merchant,
        'api_key' => $key,
        'endpoint' => 'https://sandbox.duitku.com/webapi/api/merchant/transactionStatus',
    ];
}

/**
 * Query one Duitku transaction on an explicit server-side request.
 * This build intentionally permits sandbox only; no polling or state mutation.
 * The optional transport seam is available only in APP_ENV=test.
 */
function duitku_query_transaction_status(array $config, string $merchantOrderId, ?callable $testTransport = null): array
{
    $endpoint = 'https://sandbox.duitku.com/webapi/api/merchant/transactionStatus';
    if (($config['endpoint'] ?? null) !== $endpoint) {
        throw new RuntimeException('Only the configured Duitku sandbox may be used.');
    }
    if ($merchantOrderId === '' || strlen($merchantOrderId) > 50) {
        throw new InvalidArgumentException('Duitku merchant order ID is invalid.');
    }
    $payload = [
        'merchantCode' => (string)$config['merchant_code'],
        'merchantOrderId' => $merchantOrderId,
        'signature' => duitku_transaction_status_signature($config, $merchantOrderId),
    ];
    if ($testTransport !== null) {
        if (strtolower((string)(getenv('APP_ENV') ?: 'production')) !== 'test') {
            throw new RuntimeException('Injected Duitku transport is available only in tests.');
        }
        $response = $testTransport($endpoint, $payload);
        if (!is_array($response)) {
            throw new RuntimeException('Duitku returned an invalid test response.');
        }
        return $response;
    }
    return provider_http_json($endpoint, $payload, [], 15);
}
