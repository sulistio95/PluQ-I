<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/config/database.php';

function http_reconcile_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function http_reconcile_session(int $userId, string $csrf): string
{
    $sessionId = bin2hex(random_bytes(24));
    session_name('PLUQI_ADMIN');
    session_id($sessionId);
    if (!session_start()) {
        throw new RuntimeException('Could not create an isolated admin test session.');
    }
    $_SESSION = ['admin_user_id' => $userId, 'admin_csrf' => $csrf];
    session_write_close();
    return $sessionId;
}

/** @return array{status:int,body:string} */
function http_reconcile_request(string $url, string $method = 'GET', ?string $sessionId = null, array $post = []): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Could not initialize HTTP test client.');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Connection: close'],
    ];
    if ($sessionId !== null) {
        $options[CURLOPT_COOKIE] = 'PLUQI_ADMIN=' . $sessionId;
    }
    if ($method === 'POST') {
        $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    if ($body === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('HTTP test request failed: ' . $error);
    }
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['status' => $status, 'body' => (string)$body];
}

$pdo = database();
$tag = bin2hex(random_bytes(5));
$adminUsername = 'http_reconcile_admin_' . $tag;
$operatorUsername = 'http_reconcile_operator_' . $tag;
$adminEmail = $adminUsername . '@example.test';
$operatorEmail = $operatorUsername . '@example.test';
$createdIds = [];
$baseUrl = 'http://127.0.0.1:8080/admin/reconcile.php';

try {
    $insert = $pdo->prepare("INSERT INTO admin_users (username,email,password_hash,role,status) VALUES (:username,:email,:hash,:role,'ACTIVE')");
    foreach ([
        ['username' => $adminUsername, 'email' => $adminEmail, 'role' => 'ADMIN'],
        ['username' => $operatorUsername, 'email' => $operatorEmail, 'role' => 'OPERATOR'],
    ] as $user) {
        $insert->execute([
            'username' => $user['username'],
            'email' => $user['email'],
            'hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'role' => $user['role'],
        ]);
        $createdIds[$user['role']] = (int)$pdo->lastInsertId();
    }

    $anonymous = http_reconcile_request($baseUrl);
    http_reconcile_assert($anonymous['status'] === 302, 'Anonymous reconciliation GET must redirect to admin login.');

    $operatorSession = http_reconcile_session($createdIds['OPERATOR'], bin2hex(random_bytes(32)));
    $operator = http_reconcile_request($baseUrl, 'GET', $operatorSession);
    http_reconcile_assert($operator['status'] === 403, 'Operator role must be forbidden from reconciliation.');

    $csrf = bin2hex(random_bytes(32));
    $adminSession = http_reconcile_session($createdIds['ADMIN'], $csrf);
    $invalidCsrf = http_reconcile_request($baseUrl, 'POST', $adminSession, [
        'csrf' => str_repeat('0', 64),
        'check_status' => '1',
        'merchant_order_id' => 'not-a-real-order',
    ]);
    http_reconcile_assert($invalidCsrf['status'] === 403, 'Admin POST with an invalid CSRF token must be forbidden.');

    $validCsrfUnknownAction = http_reconcile_request($baseUrl, 'POST', $adminSession, [
        'csrf' => $csrf,
        'merchant_order_id' => 'not-a-real-order',
    ]);
    http_reconcile_assert($validCsrfUnknownAction['status'] === 400, 'Valid-CSRF POST without the explicit action must be rejected as a bad request.');

    $adminPage = http_reconcile_request($baseUrl, 'GET', $adminSession);
    http_reconcile_assert($adminPage['status'] === 200 && str_contains($adminPage['body'], 'Periksa status Duitku (sandbox)'), 'Authorized admin should see the read-only sandbox reconciliation form.');

    echo "Admin reconciliation HTTP boundary tests passed: anonymous redirect, operator forbidden, invalid CSRF forbidden, unknown POST rejected, authorized admin form rendered; no gateway request was made.\n";
} finally {
    if ($createdIds !== []) {
        $ids = array_values(array_map('intval', $createdIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM admin_users WHERE id IN ($placeholders)")->execute($ids);
    }
}
