<?php
declare(strict_types=1);

/**
 * Source-level regression checks for supplier-price filtering and pagination.
 * This test does not connect to or modify a database. Runtime SQL behavior
 * must still be verified against a disposable MySQL database before deployment.
 */
$path = dirname(__DIR__) . '/public/admin/supplier-prices.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "Unable to read supplier-prices.php\n");
    exit(1);
}

$checks = [
    'product rows require an active supplier mapping' =>
        preg_match("~JOIN\\s+game_provider_mappings\\s+gm\\s+ON\\s+gm\\.provider_id\\s*=\\s*pp\\.provider_id\\s+AND\\s+gm\\.provider_game_code\\s*=\\s*pp\\.provider_game_code\\s+AND\\s+gm\\.status\\s*=\\s+'ACTIVE'~i", $source) === 1,
    'product rows require an active game' =>
        preg_match("~JOIN\\s+games\\s+g\\s+ON\\s+g\\.id\\s*=\\s*gm\\.game_id\\s+AND\\s+g\\.status\\s*=\\s+'ACTIVE'~i", $source) === 1,
    'game dropdown excludes inactive games and requires an active mapping' =>
        str_contains($source, "WHERE g.status='ACTIVE' AND EXISTS (SELECT 1 FROM game_provider_mappings gm WHERE gm.game_id=g.id AND gm.status='ACTIVE')"),
    'game filter retains the active-game guard' =>
        str_contains($source, "gm.game_id=:game_id AND g.status='ACTIVE'"),
    'supplier cost, availability, and sync time remain selected' =>
        str_contains($source, 'pp.base_price,pp.is_available,pp.synced_at'),
    'selling price and profit remain displayed' =>
        str_contains($source, 'AS selling_price') && str_contains($source, 'supplier_prices_money($sell-$base)'),
    'catalog remains read-only' =>
        str_contains($source, 'tidak mengubah harga dan tidak membuat pesanan'),
    'page size is selectable and bounded' =>
        str_contains($source, 'in_array($perPage, [25, 50, 100, 200], true)'),
    'query uses pagination instead of a fixed 200-row cap' =>
        str_contains($source, 'LIMIT " . $perPage . " OFFSET " . $offset') && !str_contains($source, 'LIMIT 200'),
    'pagination links preserve filters and sort order' =>
        str_contains($source, '$queryBase=$filters') && str_contains($source, "\$queryBase['sort']=\$sort"),
    'horizontal table scrollbar remains available' =>
        str_contains($source, 'supplier-prices-scroll-top') && str_contains($source, 'supplier-prices-table-wrap'),
    'admin sidebar remains included' =>
        str_contains($source, "app/views/admin_sidebar.php"),
];

$failed = [];
foreach ($checks as $name => $passed) {
    printf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $name);
    if (!$passed) {
        $failed[] = $name;
    }
}
if ($failed !== []) {
    fwrite(STDERR, count($failed) . " supplier price regression check(s) failed.\n");
    exit(1);
}
printf("All %d supplier price regression checks passed.\n", count($checks));
