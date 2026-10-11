#!/usr/bin/env bash
set -euo pipefail

# Two concurrent 300,000 IDR holds compete for a 500,000 IDR provider capacity.
# Exactly one must reserve capacity; a race that lets both pass fails this test.
read -r ORDER_A ORDER_B < <(php <<'PHP'
<?php
require __DIR__ . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';
$pdo = database();
$pdo->beginTransaction();
try {
    $pdo->exec("UPDATE providers SET balance=1000000,status='ACTIVE',reserve_percentage=50.00 WHERE code='DIGI'");
    $pdo->exec("INSERT INTO games (slug,name,status) VALUES ('reservation-integration-game','Reservation Integration Game','ACTIVE')
        ON DUPLICATE KEY UPDATE status='ACTIVE'");
    $gameId = (int)$pdo->query("SELECT id FROM games WHERE slug='reservation-integration-game'")->fetchColumn();
    $q = $pdo->prepare("INSERT INTO products (game_id,provider_id,provider_product_code,name,base_price,selling_price,status)
        SELECT :game_id,id,'reservation-integration-sku','Reservation Integration Product',300000,301000,'ACTIVE'
        FROM providers WHERE code='DIGI'
        ON DUPLICATE KEY UPDATE base_price=VALUES(base_price),selling_price=VALUES(selling_price),status='ACTIVE'");
    $q->execute(['game_id'=>$gameId]);
    $q = $pdo->prepare("SELECT id,provider_id FROM products WHERE provider_product_code='reservation-integration-sku' AND provider_id=(SELECT id FROM providers WHERE code='DIGI')");
    $q->execute(); $product=$q->fetch(PDO::FETCH_ASSOC);
    $ids=[];
    for ($i=0; $i<2; $i++) {
        $publicId=bin2hex(random_bytes(13));
        $q=$pdo->prepare("INSERT INTO orders (public_id,game_id,product_id,provider_id,customer_game_data,base_price,selling_price,admin_fee,payment_status,order_status)
            VALUES (:public_id,:game_id,:product_id,:provider_id,JSON_OBJECT('user_id','123'),300000,301000,1000,'PENDING','PENDING')");
        $q->execute(['public_id'=>$publicId,'game_id'=>$gameId,'product_id'=>$product['id'],'provider_id'=>$product['provider_id']]);
        $ids[]=(int)$pdo->lastInsertId();
    }
    $pdo->commit();
    echo $ids[0]." ".$ids[1].PHP_EOL;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1);
}
PHP
)

TMP_A="$(mktemp)"
TMP_B="$(mktemp)"
cleanup() { rm -f "$TMP_A" "$TMP_B"; }
trap cleanup EXIT
php tests/provider_reservation_concurrency.php "$ORDER_A" >"$TMP_A" &
PID_A=$!
php tests/provider_reservation_concurrency.php "$ORDER_B" >"$TMP_B" &
PID_B=$!
wait "$PID_A"
wait "$PID_B"

RESULTS="$(cat "$TMP_A" "$TMP_B" | sort)"
if [ "$RESULTS" != "$(printf 'REJECTED\nRESERVED')" ]; then
  echo "Expected one RESERVED and one REJECTED result; got:"
  cat "$TMP_A" "$TMP_B"
  exit 1
fi

ACTIVE="$(mysql -N -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -e "SELECT COUNT(*) FROM provider_balance_reservations WHERE provider_id=(SELECT id FROM providers WHERE code='DIGI') AND (status IN ('COMMITTED','CONSUMED') OR (status='HOLD' AND expires_at>NOW()))")""
if [ "$ACTIVE" -lt 1 ]; then
  echo "No active reservation found after concurrent attempts."
  exit 1
fi

echo "Provider balance concurrency test passed: one reservation accepted, one rejected."
