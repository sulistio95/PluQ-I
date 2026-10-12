<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/duitku.php';

$cases = [
    '00' => 'PAID',
    '01' => 'PENDING',
];
foreach ($cases as $code => $expected) {
    $actual = duitku_callback_result_state($code);
    if ($actual !== $expected) {
        fwrite(STDERR, "Expected {$code} => {$expected}, got {$actual}.\n");
        exit(1);
    }
}

try {
    duitku_callback_result_state('99');
    fwrite(STDERR, "Unsupported result code should throw.\n");
    exit(1);
} catch (InvalidArgumentException $expected) {
    // Expected: only documented success/pending states may be accepted by this endpoint.
}

echo "Duitku callback result-state tests passed.\n";
