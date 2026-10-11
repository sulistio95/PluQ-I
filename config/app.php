<?php
declare(strict_types=1);

return [
    'name' => getenv('APP_NAME') ?: 'PluQ-I',
    'env' => getenv('APP_ENV') ?: 'production',
    'debug' => filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'url' => rtrim(getenv('APP_URL') ?: '', '/'),
    'key' => getenv('APP_KEY') ?: '',
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Jakarta',
    'scheduler_token' => getenv('SCHEDULER_TOKEN') ?: '',
    'scheduler_batch_size' => max(1, min(20, (int) (getenv('SCHEDULER_BATCH_SIZE') ?: 5))),
    'scheduler_max_runtime_seconds' => max(5, min(50, (int) (getenv('SCHEDULER_MAX_RUNTIME_SECONDS') ?: 20))),
];
