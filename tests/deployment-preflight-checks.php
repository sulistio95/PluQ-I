<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$preflight = file_get_contents($root . '/bin/preflight.php');
$workflow = file_get_contents($root . '/.github/workflows/fulfillment-tests.yml');
$sidebar = file_get_contents($root . '/app/views/admin_sidebar.php');
foreach (['preflight' => $preflight, 'workflow' => $workflow, 'sidebar' => $sidebar] as $name => $source) {
    if (!is_string($source)) {
        fwrite(STDERR, "FAIL: unable to read {$name}.\n");
        exit(1);
    }
}
$checks = [
    'preflight requires order_ratings table' => str_contains($preflight, "'order_ratings'"),
    'preflight requires migration 013' => str_contains($preflight, "'013_game_username_check_protection'"),
    'preflight requires migration 014' => str_contains($preflight, "'014_order_ratings_and_supplier_quality'"),
    'MySQL integration applies migration 013' => str_contains($workflow, 'database/migrations/013_game_username_check_protection.sql'),
    'MySQL integration applies migration 014 after 013' => strpos($workflow, 'database/migrations/014_order_ratings_and_supplier_quality.sql') > strpos($workflow, 'database/migrations/013_game_username_check_protection.sql'),
    'supplier quality page is linked from admin sidebar' => str_contains($sidebar, "'href' => '/admin/supplier-quality.php'"),
];
$failed = [];
foreach ($checks as $name => $ok) {
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (!$ok) $failed[] = $name;
}
if ($failed !== []) {
    fwrite(STDERR, count($failed) . " deployment-readiness check(s) failed.\n");
    exit(1);
}
printf("All %d deployment-readiness checks passed.\n", count($checks));
