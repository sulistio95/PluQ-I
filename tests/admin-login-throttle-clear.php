<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/admin_auth.php';

final class CapturingAdminLoginStatement extends PDOStatement
{
    public array $boundValues = [];

    public function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->boundValues = $params ?? [];
        return true;
    }
}

final class CapturingAdminLoginPDO extends PDO
{
    public string $capturedSql = '';
    public ?CapturingAdminLoginStatement $capturedStatement = null;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->capturedSql = $query;
        $this->capturedStatement = new CapturingAdminLoginStatement();
        return $this->capturedStatement;
    }
}

$pdo = new CapturingAdminLoginPDO();
admin_login_clear_failures($pdo, '203.0.113.10', 'AdminUser');

if (!str_contains($pdo->capturedSql, 'ip_address = :ip AND username_hash = :username_hash')) {
    fwrite(STDERR, "Successful login must clear only the matching IP and username pair.\n");
    exit(1);
}
if (str_contains($pdo->capturedSql, 'ip_address = :ip OR username_hash = :username_hash')) {
    fwrite(STDERR, "Successful login must not clear unrelated IP or username attempts.\n");
    exit(1);
}

$expectedHash = hash('sha256', mb_strtolower(trim('AdminUser'), 'UTF-8'));
$actual = $pdo->capturedStatement?->boundValues ?? [];
if (($actual['ip'] ?? null) !== '203.0.113.10' || ($actual['username_hash'] ?? null) !== $expectedHash) {
    fwrite(STDERR, "Login throttle cleanup must bind the normalized IP and username hash.\n");
    exit(1);
}

echo "Admin login throttle cleanup regression test passed.\n";
