<?php
declare(strict_types=1);

/**
 * Read-only queue health snapshot.
 * Usage: php bin/queue-health.php
 * This script never claims, retries, or changes a job.
 */
require dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This diagnostic is CLI-only.\n");
    exit(2);
}

try {
    $pdo = database();
    $counts = array_fill_keys(['PENDING','RETRY','PROCESSING','DONE','FAILED'], 0);
    foreach ($pdo->query("SELECT status, COUNT(*) AS total FROM job_queue GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists($row['status'], $counts)) $counts[$row['status']] = (int)$row['total'];
    }

    $q = $pdo->query("SELECT
        TIMESTAMPDIFF(SECOND, MIN(available_at), NOW()) AS oldest_ready_seconds
        FROM job_queue WHERE status IN ('PENDING','RETRY') AND available_at <= NOW()");
    $oldestReady = $q->fetchColumn();
    $q = $pdo->query("SELECT COUNT(*) FROM job_queue
        WHERE status='PROCESSING' AND locked_at IS NOT NULL
          AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $staleProcessing = (int)$q->fetchColumn();
    $q = $pdo->query("SELECT COUNT(*) FROM job_queue
        WHERE status='FAILED' AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $failedLastHour = (int)$q->fetchColumn();

    $warnings = [];
    if ($counts['PENDING'] + $counts['RETRY'] >= 100) $warnings[] = 'READY_BACKLOG_AT_LEAST_100';
    if ($oldestReady !== false && $oldestReady !== null && (int)$oldestReady >= 300) $warnings[] = 'OLDEST_READY_JOB_AT_LEAST_5_MINUTES';
    if ($staleProcessing > 0) $warnings[] = 'STALE_PROCESSING_LOCKS_REQUIRE_REVIEW';
    if ($failedLastHour > 0) $warnings[] = 'FAILED_JOBS_IN_LAST_HOUR';

    $report = [
        'status' => $warnings ? 'warning' : 'ok',
        'generated_at' => date(DATE_ATOM),
        'queue_counts' => $counts,
        'ready_jobs' => $counts['PENDING'] + $counts['RETRY'],
        'oldest_ready_job_age_seconds' => $oldestReady === false || $oldestReady === null ? null : (int)$oldestReady,
        'stale_processing_jobs_over_10m' => $staleProcessing,
        'failed_jobs_last_hour' => $failedLastHour,
        'warnings' => $warnings,
        'read_only' => true,
    ];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($warnings ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "Queue health check failed; inspect database connectivity and schema.\n");
    exit(2);
}
