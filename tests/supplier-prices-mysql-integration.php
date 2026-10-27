<?php
declare(strict_types=1);

/**
 * MySQL integration regression test for supplier-price filtering and pagination.
 * Uses temporary tables only; no application or production database is touched.
 */
$dsn = getenv('TEST_MYSQL_DSN') ?: '';
$user = getenv('TEST_MYSQL_USER') ?: '';
$password = getenv('TEST_MYSQL_PASSWORD') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "TEST_MYSQL_DSN is required.\n");
    exit(1);
}

$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$ddl = [
    "CREATE TEMPORARY TABLE providers (id INT PRIMARY KEY, code VARCHAR(20), name VARCHAR(100))",
    "CREATE TEMPORARY TABLE games (id INT PRIMARY KEY, name VARCHAR(100), status VARCHAR(10))",
    "CREATE TEMPORARY TABLE game_provider_mappings (id INT PRIMARY KEY, game_id INT, provider_id INT, provider_game_code VARCHAR(100), status VARCHAR(10))",
    "CREATE TEMPORARY TABLE provider_products (id INT PRIMARY KEY, provider_id INT, provider_game_code VARCHAR(100), provider_product_code VARCHAR(100), product_name VARCHAR(100), base_price DECIMAL(18,2), is_available TINYINT, synced_at DATETIME)",
    "CREATE TEMPORARY TABLE products (id INT PRIMARY KEY, provider_id INT, provider_product_code VARCHAR(100), base_price DECIMAL(18,2), selling_price DECIMAL(18,2), status VARCHAR(20))",
    "CREATE TEMPORARY TABLE pricing_rules (id INT PRIMARY KEY, min_amount DECIMAL(18,2), max_amount DECIMAL(18,2), profit_type VARCHAR(20), profit_value DECIMAL(18,2), status VARCHAR(10))",
];
foreach ($ddl as $sql) {
    $pdo->exec($sql);
}

$seed = [
    "INSERT INTO providers VALUES (1,'VIPAY','VIPayment'),(2,'DIGI','Digiflazz')",
    "INSERT INTO games VALUES (1,'Game Aktif','ACTIVE'),(2,'Game Nonaktif','INACTIVE')",
    "INSERT INTO game_provider_mappings VALUES (1,1,1,'game-a','ACTIVE'),(2,2,1,'game-b','ACTIVE'),(3,1,2,'game-c','INACTIVE'),(4,1,2,'game-d','ACTIVE')",
    "INSERT INTO provider_products VALUES (1,1,'game-a','a-1','Paket A',1000,1,NOW()),(2,1,'game-a','a-2','Paket B',2000,1,NOW()),(3,1,'game-b','b-1','Paket Game Nonaktif',500,1,NOW()),(4,2,'game-c','c-1','Paket Mapping Nonaktif',300,1,NOW()),(5,2,'game-d','d-1','Paket D',1500,0,NOW())",
    "INSERT INTO products VALUES (1,1,'a-1',1000,1500,'ACTIVE'),(2,1,'a-2',2000,3000,'ACTIVE'),(3,2,'d-1',1500,2000,'ACTIVE')",
    "INSERT INTO pricing_rules VALUES (1,0,10000,'FIXED',500,'ACTIVE')",
];
foreach ($seed as $sql) {
    $pdo->exec($sql);
}

$from = " FROM provider_products pp JOIN providers pr ON pr.id=pp.provider_id
 JOIN game_provider_mappings gm ON gm.provider_id=pp.provider_id AND gm.provider_game_code=pp.provider_game_code AND gm.status='ACTIVE'
 JOIN games g ON g.id=gm.game_id AND g.status='ACTIVE'
 LEFT JOIN products p ON p.provider_id=pp.provider_id AND p.provider_product_code=pp.provider_product_code";
$checks = [];
$assert = static function (string $name, bool $ok) use (&$checks): void {
    $checks[] = [$name, $ok];
};
$count = static function (string $whereSql = '', array $params = []) use ($pdo, $from): int {
    $stmt = $pdo->prepare('SELECT COUNT(*)' . $from . $whereSql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};

$assert('inactive games and inactive mappings are excluded', $count() === 3);
$assert('provider filter limits results', $count(' WHERE pr.code=:provider', ['provider' => 'VIPAY']) === 2);
$assert('active game filter limits results', $count(" WHERE gm.game_id=:game_id AND g.status='ACTIVE'", ['game_id' => 1]) === 2);
$assert('search filter matches product names and codes', $count(' WHERE (pp.product_name LIKE :q1 OR pp.provider_product_code LIKE :q2 OR pp.provider_game_code LIKE :q3)', ['q1' => '%Paket B%', 'q2' => '%Paket B%', 'q3' => '%Paket B%']) === 1);
$assert('no-match filters return zero rows', $count(' WHERE pp.product_name LIKE :q', ['q' => '%tidak-ada%']) === 0);

$pageSql = 'SELECT pp.provider_product_code, pp.base_price' . $from . " ORDER BY pp.base_price ASC, pr.name, pp.product_name, pp.provider_product_code LIMIT 2 OFFSET 0";
$page1 = $pdo->query($pageSql)->fetchAll();
$page2 = $pdo->query(str_replace('LIMIT 2 OFFSET 0', 'LIMIT 2 OFFSET 2', $pageSql))->fetchAll();
$assert('page size is enforced', count($page1) === 2);
$assert('last page returns remaining row', count($page2) === 1);
$assert('ascending order is deterministic across pages', (float) $page1[0]['base_price'] < (float) $page1[1]['base_price'] && (float) $page1[1]['base_price'] < (float) $page2[0]['base_price']);
$desc = $pdo->query('SELECT pp.base_price' . $from . ' ORDER BY pp.base_price DESC LIMIT 1')->fetchColumn();
$assert('descending sort returns highest active mapped price', (float) $desc === 2000.0);

$failed = 0;
foreach ($checks as [$name, $ok]) {
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (!$ok) {
        $failed++;
    }
}
if ($failed > 0) {
    fwrite(STDERR, "$failed supplier-price MySQL integration check(s) failed.\n");
    exit(1);
}
printf("All %d supplier-price MySQL integration checks passed.\n", count($checks));
