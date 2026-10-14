<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/checkout_quote.php';

function expect_gate(bool $expected, string $message): void
{
    if (checkout_payments_enabled() !== $expected) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function expect_sandbox_gate(bool $expected, string $message): void
{
    if (checkout_sandbox_only_enabled() !== $expected) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

putenv('APP_ENV');
putenv('DUITKU_ENV');
putenv('CHECKOUT_PAYMENTS_ENABLED');
putenv('SUPPLIER_ORDERS_ENABLED');
expect_gate(false, 'Checkout is disabled by default.');
expect_sandbox_gate(false, 'Sandbox-only checkout is disabled by default.');

putenv('CHECKOUT_PAYMENTS_ENABLED=true');
putenv('SUPPLIER_ORDERS_ENABLED');
expect_gate(false, 'Payments must stay disabled outside explicit sandbox-only or supplier-enabled mode.');

putenv('APP_ENV=staging');
putenv('DUITKU_ENV=sandbox');
expect_gate(true, 'Staging may accept sandbox checkout while supplier orders are disabled.');
expect_sandbox_gate(true, 'Staging sandbox-only mode should be enabled with explicit safe flags.');

putenv('APP_ENV=production');
expect_gate(false, 'Production must not use sandbox-only checkout without supplier ordering.');
expect_sandbox_gate(false, 'Sandbox-only checkout must never be enabled in production.');

putenv('APP_ENV=staging');
putenv('DUITKU_ENV=production');
expect_gate(false, 'Sandbox-only checkout must reject a non-sandbox Duitku environment.');
expect_sandbox_gate(false, 'Sandbox-only checkout requires DUITKU_ENV=sandbox.');

putenv('DUITKU_ENV=sandbox');
putenv('SUPPLIER_ORDERS_ENABLED=true');
expect_gate(true, 'Normal checkout remains enabled only when both payment and supplier flags are true.');
expect_sandbox_gate(false, 'Sandbox-only mode must be off when supplier ordering is enabled.');

putenv('APP_ENV');
putenv('DUITKU_ENV');
putenv('CHECKOUT_PAYMENTS_ENABLED');
putenv('SUPPLIER_ORDERS_ENABLED');

fwrite(STDOUT, "Checkout payment gate tests passed.\\n");
