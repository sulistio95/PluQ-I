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

echo "Admin authorization role tests passed.\n";
