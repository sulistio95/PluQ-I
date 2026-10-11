<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/catalog_sync.php';

$checks = [
    'exclude joki service' => catalog_sync_is_excluded('Mobile Legends Joki Ranked', 'ML-JOKI-01') === true,
    'exclude vilog service' => catalog_sync_is_excluded('Topup via Vilog', 'VILOG-01') === true,
    'allow ordinary product' => catalog_sync_is_excluded('Mobile Legends 86 Diamonds', 'ML86') === false,
    'trim product text' => catalog_sync_text('  Game A  ') === 'Game A',
    'truncate provider code' => strlen(catalog_sync_text(str_repeat('A', 250))) === 190,
    'round whole rupiah' => catalog_sync_price('1234.6') === 1235,
    'reject negative price' => false,
];

try {
    catalog_sync_price(-1);
} catch (RuntimeException) {
    $checks['reject negative price'] = true;
}

$failed = [];
foreach ($checks as $name => $ok) {
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (!$ok) {
        $failed[] = $name;
    }
}
if ($failed !== []) {
    fwrite(STDERR, count($failed) . " catalog sync test(s) failed.\n");
    exit(1);
}
printf("All %d catalog sync tests passed.\n", count($checks));
