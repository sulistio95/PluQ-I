<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'method_not_allowed'], 405);
}
$payload = $_POST['payload'] ?? '';
if (!is_string($payload) || strlen($payload) > 16000) {
    json_response(['error' => 'invalid_payload'], 413);
}
$input = json_decode($payload, true, 32, JSON_BIGINT_AS_STRING);
if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) {
    json_response(['error' => 'invalid_payload'], 400);
}
try {
    require_once PLUQI_ROOT . '/config/database.php';
    require_once PLUQI_ROOT . '/app/services/checkout_quote.php';
    json_response(['data' => checkout_quote(database(), $input)]);
} catch (InvalidArgumentException $e) {
    json_response(['error' => 'invalid_input', 'message' => $e->getMessage()], 422);
} catch (DomainException $e) {
    json_response(['error' => 'not_available', 'message' => $e->getMessage()], 409);
} catch (Throwable $e) {
    json_response(['error' => 'quote_unavailable'], 503);
}
