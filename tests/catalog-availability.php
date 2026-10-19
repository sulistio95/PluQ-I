<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/catalog.php';

$unavailableVipay = [
    ['id' => 21, 'name' => '86 Diamonds', 'base_price' => 14000, 'selling_price' => 15000, 'is_available' => 0, 'provider' => 'VIPAY'],
];
$availableDigiAndZeroBalanceVipay = [
    ['id' => 21, 'name' => '86 Diamonds', 'selling_price' => 15000, 'is_available' => 0, 'provider' => 'VIPAY'],
    ['id' => 11, 'name' => '86 Diamonds', 'base_price' => 15000, 'selling_price' => 16000, 'is_available' => 1, 'provider' => 'DIGI'],
];
$twoAvailableOffers = [
    ['id' => 11, 'name' => '86 Diamonds', 'selling_price' => 16000, 'is_available' => 1, 'provider' => 'DIGI'],
    ['id' => 21, 'name' => '86 Diamonds', 'selling_price' => 15000, 'is_available' => 1, 'provider' => 'VIPAY'],
    ['id' => 31, 'name' => 'Weekly Pass', 'base_price' => 27000, 'selling_price' => 28000, 'is_available' => 1, 'provider' => 'DIGI'],
];
$checks = [
    'keep denomination visible but unavailable when its only supplier is unavailable' =>
        count(catalog_select_customer_products($unavailableVipay)) === 1
        && catalog_select_customer_products($unavailableVipay)[0]['is_available'] === false,
    'prefer available Digiflazz offer over zero-balance VIPayment' =>
        count(catalog_select_customer_products($availableDigiAndZeroBalanceVipay)) === 1
        && (int) catalog_select_customer_products($availableDigiAndZeroBalanceVipay)[0]['id'] === 11
        && catalog_select_customer_products($availableDigiAndZeroBalanceVipay)[0]['is_available'] === true,
    'choose cheapest offer when both suppliers are available' =>
        (int) catalog_select_customer_products($twoAvailableOffers)[0]['id'] === 21,
    'retain other available product groups' =>
        count(catalog_select_customer_products($twoAvailableOffers)) === 2,
    'mark cheapest cached offer unavailable when no supplier can fulfill it' =>
        (function (): bool {
            $rows = [
                ['id' => 21, 'name' => '86 Diamonds', 'selling_price' => 15000, 'is_available' => 0],
                ['id' => 11, 'name' => '86 Diamonds', 'selling_price' => 16000, 'is_available' => 0],
            ];
            $selected = catalog_select_customer_products($rows);
            return count($selected) === 1
                && (int) $selected[0]['id'] === 21
                && $selected[0]['is_available'] === false;
        })(),
    'ignore malformed rows and blank product names' =>
        catalog_select_customer_products([[], ['name' => ' ', 'selling_price' => 1, 'is_available' => 1]]) === [],
];

$failed = [];
foreach ($checks as $name => $ok) {
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (!$ok) {
        $failed[] = $name;
    }
}
if ($failed !== []) {
    fwrite(STDERR, count($failed) . " catalog availability test(s) failed.\n");
    exit(1);
}
printf("All %d catalog availability tests passed.\n", count($checks));
