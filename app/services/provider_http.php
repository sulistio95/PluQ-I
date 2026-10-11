<?php
declare(strict_types=1);

/**
 * Minimal JSON HTTP transport for server-side supplier integrations.
 * Never logs authorization headers or credential-bearing request data.
 */
function provider_http_json(string $url, array $payload, array $headers = [], int $timeoutSeconds = 15): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required.');
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['https'], true) || empty($parts['host'])) {
        throw new InvalidArgumentException('Provider endpoint must be an HTTPS URL.');
    }
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize HTTP client.');
    }
    $baseHeaders = ['Accept: application/json', 'Content-Type: application/json'];
    foreach ($headers as $header) {
        if (is_string($header) && !preg_match('/[\r\n]/', $header)) {
            $baseHeaders[] = $header;
        }
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $baseHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => max(5, min(30, $timeoutSeconds)),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($handle) ? curl_error($handle) : null;
    curl_close($handle);
    if ($response === false || $error !== null) {
        throw new RuntimeException('Provider network request failed.');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Provider returned HTTP ' . $status . '.');
    }
    try {
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException('Provider returned invalid JSON.', 0, $e);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Provider returned an unexpected response.');
    }
    return $decoded;
}
