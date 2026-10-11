<?php
declare(strict_types=1);

/**
 * Read-only report for orders requiring manual reconciliation.
 * Does not change order/payment state, retry supplier requests, or create refunds.
 * Usage: php bin/inspect-review.php [limit]
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This diagnostic is CLI-only.\n");
    exit(2);
}
$limit = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : 50;
if ($limit === false || $limit < 1 || $limit > 200) {
    fwrite(STDERR, "Usage: php bin/inspect-review.php [limit: 1..200]\n");
    exit(2);
}

$pdo = database();
$sql = "
SELECT
  o.id AS internal_order_id,
  o.public_id AS order_number,
  o.created_at,
  o.paid_at,
  o.payment_status,
  o.order_status,
  o.selling_price,
  o.admin_fee,
  pr.code AS provider_code,
  st.status AS supplier_status,
  st.reference_id AS local_supplier_reference,
  st.provider_reference,
  st.attempts AS supplier_attempts,
  st.last_error AS supplier_error,
  pt.status AS gateway_payment_status,
  pt.gateway_reference,
  pt.gateway_payment_url,
  (SELECT r.status FROM refunds r WHERE r.order_id = o.id ORDER BY r.id DESC LIMIT 1) AS latest_refund_status,
  o.last_error AS order_error
FROM orders o
JOIN providers pr ON pr.id = o.provider_id
LEFT JOIN supplier_transactions st ON st.order_id = o.id
LEFT JOIN payment_transactions pt ON pt.order_id = o.id
WHERE o.order_status = 'MANUAL_REVIEW'
   OR st.status = 'REVIEW'
   OR (o.payment_status = 'PENDING' AND pt.status = 'PENDING'
       AND pt.gateway_payment_url IS NULL
       AND o.last_error = 'Duitku sandbox request needs manual review')
   OR (o.payment_status = 'REFUND_PENDING' AND NOT EXISTS (
       SELECT 1 FROM refunds r WHERE r.order_id = o.id AND r.status IN ('PENDING','PROCESSING','SUCCESS')
   ))
ORDER BY COALESCE(o.paid_at, o.created_at) ASC
LIMIT :row_limit";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':row_limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    fwrite(STDOUT, "No orders currently require manual review.\n");
    exit(0);
}
fwrite(STDOUT, "READ-ONLY REPORT: verify supplier and payment records before retrying or refunding.\n");
fwrite(STDOUT, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
fwrite(STDOUT, "Found " . count($rows) . " review/refund-reconciliation row(s). No data was changed.\n");
