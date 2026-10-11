<?php
declare(strict_types=1);

/**
 * Read-only diagnostics for abandoned queue jobs.
 * This command does not retry supplier requests, mutate order state, or create refunds.
 * Usage: php bin/inspect-queue.php [age-minutes]
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This diagnostic is CLI-only.\n");
    exit(2);
}
$ageMinutes = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : 10;
if ($ageMinutes === false || $ageMinutes < 1 || $ageMinutes > 10080) {
    fwrite(STDERR, "Usage: php bin/inspect-queue.php [age-minutes: 1..10080]\n");
    exit(2);
}
$pdo = database();
$sql = "
SELECT
  jq.id AS job_id,
  jq.job_type,
  jq.status AS job_status,
  jq.attempts AS job_attempts,
  jq.locked_at,
  jq.last_error AS job_error,
  CAST(JSON_UNQUOTE(JSON_EXTRACT(jq.payload, '$.order_id')) AS UNSIGNED) AS order_id,
  o.public_id,
  o.payment_status,
  o.order_status,
  st.status AS supplier_status,
  st.provider_reference,
  st.last_error AS supplier_error,
  pt.status AS payment_status_record,
  (SELECT r.status FROM refunds r WHERE r.order_id = o.id ORDER BY r.id DESC LIMIT 1) AS latest_refund_status
FROM job_queue jq
LEFT JOIN orders o
  ON o.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(jq.payload, '$.order_id')) AS UNSIGNED)
LEFT JOIN supplier_transactions st ON st.order_id = o.id
LEFT JOIN payment_transactions pt ON pt.order_id = o.id
WHERE jq.status = 'PROCESSING'
  AND jq.locked_at IS NOT NULL
  AND jq.locked_at < DATE_SUB(NOW(), INTERVAL :age MINUTE)
ORDER BY jq.locked_at ASC
LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':age', $ageMinutes, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    fwrite(STDOUT, "No PROCESSING queue jobs older than {$ageMinutes} minute(s).\n");
    exit(0);
}
fwrite(STDOUT, "READ-ONLY DIAGNOSTICS: do not blindly retry or refund these orders.\n");
fwrite(STDOUT, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
fwrite(STDOUT, "Found " . count($rows) . " potentially stale job row(s). Reconcile with the provider before taking action.\n");
