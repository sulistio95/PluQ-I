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

putenv('CHECKOUT_PAYMENTS_ENABLED');
putenv('SUPPLIER_ORDERS_ENABLED');
expect_gate(false, 'Checkout is disabled by default.');

putenv('CHECKOUT_PAYMENTS_ENABLED=true');
putenv('SUPPLIER_ORDERS_ENABLED');
expect_gate(false, 'Payments must stay disabled when supplier fulfillment is disabled.');

putenv('CHECKOUT_PAYMENTS_ENABLED=false');
putenv('SUPPLIER_ORDERS_ENABLED=true');
expect_gate(false, 'Supplier fulfillment alone must not enable checkout payments.');

putenv('CHECKOUT_PAYMENTS_ENABLED=true');
putenv('SUPPLIER_ORDERS_ENABLED=true');
expect_gate(true, 'Checkout is enabled only when both flags are true.');

putenv('CHECKOUT_PAYMENTS_ENABLED');
putenv('SUPPLIER_ORDERS_ENABLED');

fwrite(STDOUT, "Checkout payment gate tests passed.\n");
