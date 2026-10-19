<?php
declare(strict_types=1);

function pluqi_api_secret_key(): string
{
    $secret = (string) getenv('APP_KEY');
    if (strlen($secret) < 32 || str_contains($secret, 'replace-with')) {
        throw new RuntimeException('APP_KEY harus berupa rahasia acak minimal 32 karakter.');
    }
    return hash('sha256', $secret, true);
}

function pluqi_encrypt_api_secret(string $value): string
{
    if (!function_exists('sodium_crypto_secretbox')) {
        throw new RuntimeException('Ekstensi Sodium PHP diperlukan.');
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, pluqi_api_secret_key()));
}

function pluqi_decrypt_api_secret(string $value): string
{
    $packed = base64_decode($value, true);
    if ($packed === false || strlen($packed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        throw new RuntimeException('Kredensial API tersimpan tidak valid.');
    }
    $nonce = substr($packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open(substr($packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, pluqi_api_secret_key());
    if (!is_string($plain)) throw new RuntimeException('Tidak dapat membuka kredensial API. Periksa APP_KEY.');
    return $plain;
}

/** Values saved through the admin form take precedence over .env. */
function pluqi_api_config_value(string $name): string
{
    static $cache = [];
    if (array_key_exists($name, $cache)) return $cache[$name];
    $fallback = trim((string) getenv($name));
    if (!preg_match('/^(DUITKU|DIGIFLAZZ|VIPAYMENT)_[A-Z0-9_]+$/D', $name)) return $cache[$name] = $fallback;
    try {
        require_once PLUQI_ROOT . '/config/database.php';
        $stmt = database()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute(['key' => 'api_secret_' . $name]);
        $stored = $stmt->fetchColumn();
        if (is_string($stored) && $stored !== '') return $cache[$name] = pluqi_decrypt_api_secret($stored);
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Tidak dapat membuka kredensial') || str_contains($e->getMessage(), 'kredensial API tersimpan')) throw $e;
    }
    return $cache[$name] = $fallback;
}
