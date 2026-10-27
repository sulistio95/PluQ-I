<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/order_rating.php';

function csrf_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\\n");
        exit(1);
    }
}

$sessionToken = str_repeat('a', 64);

csrf_test(
    order_rating_csrf_valid($sessionToken, $sessionToken),
    'Matching CSRF token must pass'
);

foreach ([null, '', str_repeat('a', 63), str_repeat('b', 64), ['token']] as $token) {
    csrf_test(
        !order_rating_csrf_valid($token, $sessionToken),
        'Invalid CSRF token must fail'
    );
}

csrf_test(
    !order_rating_csrf_valid($sessionToken, null),
    'Missing session token must fail'
);

csrf_test(
    !order_rating_csrf_valid([], $sessionToken),
    'Array token must fail'
);

echo "PASS: customer rating CSRF validation\n";
