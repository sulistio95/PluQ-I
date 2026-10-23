<?php
declare(strict_types=1);

/**
 * Regression guard for the read-only supplier price admin page.
 *
 * This is a source-level guard: it does not connect to any database or alter
 * supplier catalog data. Runtime SQL behavior should additionally be checked
 * in a disposable MySQL test database before deployment.
 */
$path = dirname(__DIR__) . '/public/admin/supplier-prices.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read supplier-prices.php\n");
    exit(1);
}

$jsPath = dirname(__DIR__) . '/public/assets/catalog.js';
$jsSource = file_get_contents($jsPath);
if (!is_string($jsSource)) {
    fwrite(STDERR, "Unable to read catalog.js\\n");
    exit(1);
}

$checks = [
    'product rows require an active supplier mapping' =>
        preg_match('/JOIN\s+game_provider_mappings\s+gm\s+ON\s+gm\.provider_id\s*=\s*pp\.provider_id\s+AND\s+gm\.provider_game_code\s*=\s*pp\.provider_game_code\s+AND\s+gm\.status\s*=\s*\'ACTIVE\'/i', $source) === 1,
    'product rows require an active game' =>
        preg_match('/JOIN\s+games\s+g\s+ON\s+g\.id\s*=\s*gm\.game_id\s+AND\s+g\.status\s*=\s*\'ACTIVE\'/i', $source) === 1,
    'game dropdown excludes inactive games and requires an active mapping' =>
        str_contains($source, "WHERE g.status='ACTIVE' AND EXISTS (SELECT 1 FROM game_provider_mappings gm WHERE gm.game_id=g.id AND gm.status='ACTIVE')"),
    'game filter retains the active-game guard' =>
        str_contains($source, "gm.game_id=:game_id AND g.status='ACTIVE'"),
    'supplier cost and availability remain selected' =>
        str_contains($source, 'pp.base_price,pp.is_available,pp.synced_at'),
    'supplier sync timestamp remains selected' =>
        str_contains($source, 'pp.synced_at'),
    'selling price calculation remains available' =>
        str_contains($source, 'AS selling_price'),
    'profit remains displayed' =>
        str_contains($source, 'supplier_prices_money($sell-$base)'),
    'catalog remains read-only and no fulfillment is triggered' =>
        str_contains($source, 'tidak mengubah harga dan tidak membuat pesanan'),
    'supplier price page size is selectable and bounded' =>
        str_contains($source, "in_array($perPage, [25, 50, 100, 200], true)"),
    'supplier price query uses dynamic pagination instead of fixed 200 rows' =>
        str_contains($source, 'LIMIT " . $perPage . " OFFSET " . $offset') && !str_contains($source, 'LIMIT 200'),
    'pagination links preserve active filters and sort order' =>
        str_contains($source, "$queryBase=$filters") && str_contains($source, "$queryBase['sort']=$sort"),
    'homepage loads games and banners independently' =>
        str_contains($jsSource, 'Promise.allSettled([getJson(\'/api/games\'), getJson(\'/api/banners\')])'),
];

$failed = [];
foreach ($checks as $name => $passed) {
    printf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $name);
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== []) {
    fwrite(STDERR, count($failed) . " supplier price filtering check(s) failed.\n");
    exit(1);
}
printf("All %d supplier price filtering checks passed.\n", count($checks));
