#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"

order_id="rec1234567890abcde12345678"
mysql -h "$DB_HOST" -P "$DB_PORT" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" <<'SQL'
INSERT INTO orders (public_id, game_id, product_id, provider_id, customer_game_data, base_price, selling_price, admin_fee, payment_status, order_status)
SELECT 'rec1234567890abcde12345678', g.id, p.id, p.provider_id, JSON_OBJECT('user_id','789'), 1000, 1500, 1000, 'PAID', 'PROCESSING'
FROM games g JOIN products p ON p.game_id = g.id
WHERE g.slug = 'integration-game' AND p.provider_product_code = 'integration-sku';
INSERT INTO provider_balance_reservations (order_id, provider_id, amount, status, committed_at)
SELECT o.id, o.provider_id, o.base_price, 'COMMITTED', NOW()
FROM orders o WHERE o.public_id = 'rec1234567890abcde12345678';
INSERT INTO supplier_transactions (order_id, provider_id, reference_id, provider_reference, status, attempts)
SELECT o.id, o.provider_id, CONCAT('PQI-', o.public_id), 'SUPPLIER-RECOVERED-REF', 'SUCCESS', 1
FROM orders o WHERE o.public_id = 'rec1234567890abcde12345678';
SQL

SUPPLIER_ORDERS_ENABLED=true php -r '
require getcwd() . "/app/bootstrap.php";
require_once PLUQI_ROOT . "/config/database.php";
require_once PLUQI_ROOT . "/app/services/fulfillment.php";
$pdo = database();
$q = $pdo->prepare("SELECT id FROM orders WHERE public_id = ?");
$q->execute(["rec1234567890abcde12345678"]);
$id = (int)$q->fetchColumn();
if ($id < 1) { fwrite(STDERR, "Recovery order fixture was not created.\\n"); exit(1); }
$result = fulfillment_process($pdo, $id);
if ($result !== "DONE") { fwrite(STDERR, "Expected recovered supplier success to finish, got: " . $result . "\\n"); exit(1); }
$q = $pdo->prepare("SELECT status FROM provider_balance_reservations WHERE order_id = ?");
$q->execute([$id]);
if ($q->fetchColumn() !== "CONSUMED") { fwrite(STDERR, "Recovered success must consume the provider reservation.\\n"); exit(1); }
$q = $pdo->prepare("SELECT order_status FROM orders WHERE id = ?");
$q->execute([$id]);
if ($q->fetchColumn() !== "SUCCESS") { fwrite(STDERR, "Recovered order must be marked SUCCESS.\\n"); exit(1); }
echo "Completed supplier order reservation recovery test passed.\\n";
'
