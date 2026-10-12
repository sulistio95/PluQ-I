<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once PLUQI_ROOT . '/config/database.php';

if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
    fwrite(STDERR, "pcntl is required for the concurrent queue claim integration test.\n");
    exit(1);
}

function queue_test_fresh_connection(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = (int)(getenv('DB_PORT') ?: 3306);
    $name = getenv('DB_DATABASE') ?: '';
    $user = getenv('DB_USERNAME') ?: '';
    $pass = getenv('DB_PASSWORD') ?: '';
    return new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$host,$port,$name),$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>5,
    ]);
}
$pdo = queue_test_fresh_connection();
$ready = (int)$pdo->query("SELECT COUNT(*) FROM job_queue WHERE status IN ('PENDING','RETRY') AND available_at<=NOW()")->fetchColumn();
if ($ready !== 0) {
    fwrite(STDERR, "Queue must have no ready jobs before this isolated concurrency test; found {$ready}.\n");
    exit(1);
}

$tag = 'claimtest-' . bin2hex(random_bytes(8));
$payload = json_encode(['test_tag'=>$tag], JSON_THROW_ON_ERROR);
$ids = [];
$dir = sys_get_temp_dir() . '/' . $tag;
if (!mkdir($dir, 0700)) {
    throw new RuntimeException('Could not create temporary result directory.');
}

try {
    $insert = $pdo->prepare("INSERT INTO job_queue (job_type,payload,status,attempts,available_at)
        VALUES ('TEST_CONCURRENT_CLAIM',:payload,'PENDING',0,NOW())");
    for ($i=0; $i<2; $i++) {
        $insert->execute(['payload'=>$payload]);
        $ids[] = (int)$pdo->lastInsertId();
    }

    // Do not carry a live parent MySQL socket across fork(): inherited PDO sockets
    // can be closed by a child and make the parent's connection go away.
    $pdo = null;
    $children = [];
    for ($i=0; $i<2; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('Could not fork worker process.');
        if ($pid === 0) {
            try {
                // Each child opens its own connection, just like independent cron workers.
                $childPdo = queue_test_fresh_connection();
                $token = bin2hex(random_bytes(16));
                $childPdo->beginTransaction();
                $q = $childPdo->query("SELECT id,job_type,payload,attempts FROM job_queue
                    WHERE status IN ('PENDING','RETRY') AND available_at<=NOW()
                    ORDER BY id LIMIT 1 FOR UPDATE");
                $job = $q->fetch(PDO::FETCH_ASSOC);
                if (!$job) throw new RuntimeException('No ready job found by child.');
                usleep(250000);
                $u = $childPdo->prepare("UPDATE job_queue SET status='PROCESSING',attempts=attempts+1,locked_at=NOW(),lock_token=:token
                    WHERE id=:id AND status IN ('PENDING','RETRY')");
                $u->execute(['token'=>$token,'id'=>$job['id']]);
                if ($u->rowCount() !== 1) throw new RuntimeException('Claim did not update exactly one ready job.');
                $childPdo->commit();
                file_put_contents($dir . '/' . getmypid() . '.json', json_encode(['id'=>(int)$job['id'],'token'=>$token], JSON_THROW_ON_ERROR), LOCK_EX);
                exit(0);
            } catch (Throwable $e) {
                if (isset($childPdo) && $childPdo instanceof PDO && $childPdo->inTransaction()) $childPdo->rollBack();
                file_put_contents($dir . '/' . getmypid() . '.error', $e->getMessage(), LOCK_EX);
                exit(1);
            }
        }
        $children[] = $pid;
    }

    $failed = false;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) $failed = true;
    }
    if ($failed) {
        $errors = glob($dir . '/*.error') ?: [];
        throw new RuntimeException('Concurrent claim child failed: ' . implode('; ', array_map(static fn($p)=>file_get_contents($p), $errors)));
    }

    $pdo = queue_test_fresh_connection();
    $results = [];
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $results[] = $data;
    }
    if (count($results) !== 2 || count(array_unique(array_column($results,'id'))) !== 2) {
        throw new RuntimeException('Concurrent workers did not claim two distinct jobs: ' . json_encode($results));
    }
    $q = $pdo->prepare("SELECT id,status,lock_token,attempts FROM job_queue WHERE id IN (:a,:b) ORDER BY id");
    $q->execute(['a'=>$ids[0],'b'=>$ids[1]]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows)!==2) throw new RuntimeException('Claimed queue fixtures were not both found.');
    foreach ($rows as $row) {
        if ($row['status']!=='PROCESSING' || !is_string($row['lock_token']) || strlen($row['lock_token'])!==32 || (int)$row['attempts']!==1) {
            throw new RuntimeException('Unexpected final queue state: ' . json_encode($row));
        }
    }
    echo "Two concurrent DB workers claimed distinct jobs with separate lease tokens.\n";
} finally {
    $cleanupPdo = queue_test_fresh_connection();
    $cleanup = $cleanupPdo->prepare("DELETE FROM job_queue WHERE id=:id");
    foreach ($ids as $id) $cleanup->execute(['id'=>$id]);
    foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
    @rmdir($dir);
}
