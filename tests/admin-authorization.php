<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/admin_auth.php';

function expect_role(bool $expected, array $user, string $label): void
{
    $actual = admin_can_manage_settings($user);
    if ($actual !== $expected) {
        fwrite(STDERR, $label . ': expected ' . ($expected ? 'allowed' : 'denied')
            . ', got ' . ($actual ? 'allowed' : 'denied') . PHP_EOL);
        exit(1);
    }
}

expect_role(true, ['role' => 'SUPER_ADMIN'], 'Super admin can manage settings');
expect_role(true, ['role' => 'ADMIN'], 'Admin can manage settings');
expect_role(false, ['role' => 'OPERATOR'], 'Operator cannot manage settings');
expect_role(false, ['role' => ''], 'Empty role cannot manage settings');
expect_role(false, [], 'Missing role cannot manage settings');
expect_role(false, ['role' => 'admin'], 'Role comparison is case-sensitive');

$_SESSION = ['admin_csrf' => str_repeat('a', 64)];
if (!admin_csrf_valid(str_repeat('a', 64))) {
    fwrite(STDERR, "Matching CSRF token must be accepted.\n");
    exit(1);
}
foreach ([null, '', str_repeat('a', 63), str_repeat('b', 64), ['token']] as $invalidToken) {
    if (admin_csrf_valid($invalidToken)) {
        fwrite(STDERR, "Invalid CSRF token must be rejected.\n");
        exit(1);
    }
}
unset($_SESSION['admin_csrf']);
if (admin_csrf_valid(str_repeat('a', 64))) {
    fwrite(STDERR, "CSRF token must be rejected when session token is absent.\n");
    exit(1);
}

echo "Admin authorization and CSRF tests passed.\n";
