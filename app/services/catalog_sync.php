<?php
declare(strict_types=1);

require_once __DIR__ . '/digiflazz.php';
require_once __DIR__ . '/vipayment.php';
require_once __DIR__ . '/pricing.php';

function catalog_sync_text(mixed $value, int $maxLength = 190): string
{
    if (!is_string($value) && !is_numeric($value)) {
        return '';
    }
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
}

function catalog_sync_is_excluded(string $name, string $code): bool
{
    $text = strtolower($name . ' ' . $code);
    return str_contains($text, 'joki') || str_contains($text, 'vilog');
}

function catalog_sync_price(mixed $value): int
{
    if (!is_numeric($value)) {
        throw new RuntimeException('Provider product has an invalid price.');
    }
    $price = (float) $value;
    if (!is_finite($price) || $price < 0 || $price > 9999999999999999) {
        throw new RuntimeException('Provider product has an out-of-range price.');
    }
    return (int) round($price, 0, PHP_ROUND_HALF_UP);
}

/** Match provider game/brand names only against explicit admin-managed mappings. */
function catalog_sync_game_id(PDO $pdo, int $providerId, string $providerGameCode): ?int
{
    if ($providerGameCode === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT game_id FROM game_provider_mappings
        WHERE provider_id = :provider_id AND provider_game_code = :game_code AND status = 'ACTIVE'
        LIMIT 1");
    $stmt->execute(['provider_id' => $providerId, 'game_code' => $providerGameCode]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (int) $value;
}

/**
 * Fail closed before applying a complete provider snapshot: entries not returned
 * by a successful full catalog response stay unavailable until confirmed again.
 * The caller must run this inside the same transaction as the upserts.
 */
function catalog_sync_mark_provider_stale(PDO $pdo, int $providerId): void
{
    $providerProducts = $pdo->prepare("UPDATE provider_products SET is_available = 0 WHERE provider_id = :provider_id");
    $providerProducts->execute(['provider_id' => $providerId]);

    $storeProducts = $pdo->prepare("UPDATE products SET status = 'OUT_OF_STOCK' WHERE provider_id = :provider_id");
    $storeProducts->execute(['provider_id' => $providerId]);
}

function catalog_sync_upsert_provider_product(
    PDO $pdo,
    int $providerId,
    string $gameCode,
    string $code,
    string $name,
    string $category,
    int $basePrice,
    bool $available,
    array $raw
): void {
    $stmt = $pdo->prepare("INSERT INTO provider_products
        (provider_id, provider_game_code, provider_product_code, product_name, category, base_price, is_available, raw_data, synced_at)
        VALUES (:provider_id, :game_code, :product_code, :product_name, :category, :base_price, :available, :raw_data, NOW())
        ON DUPLICATE KEY UPDATE provider_game_code = VALUES(provider_game_code),
        product_name = VALUES(product_name), category = VALUES(category), base_price = VALUES(base_price),
        is_available = VALUES(is_available), raw_data = VALUES(raw_data), synced_at = NOW()");
    $stmt->execute([
        'provider_id' => $providerId,
        'game_code' => $gameCode !== '' ? $gameCode : null,
        'product_code' => $code,
        'product_name' => $name,
        'category' => $category !== '' ? $category : null,
        'base_price' => $basePrice,
        'available' => $available ? 1 : 0,
        'raw_data' => json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}

function catalog_sync_upsert_store_product(
    PDO $pdo,
    int $providerId,
    int $gameId,
    string $code,
    string $name,
    int $basePrice,
    bool $available,
    array $raw
): void {
    $sellingPrice = pricing_selling_price($pdo, $basePrice);
    $status = $available ? 'ACTIVE' : 'OUT_OF_STOCK';
    $stmt = $pdo->prepare("INSERT INTO products
        (game_id, provider_id, provider_product_code, name, base_price, selling_price, status, metadata, synced_at)
        VALUES (:game_id, :provider_id, :product_code, :name, :base_price, :selling_price, :status, :metadata, NOW())
        ON DUPLICATE KEY UPDATE game_id = VALUES(game_id), name = VALUES(name),
        base_price = VALUES(base_price), selling_price = VALUES(selling_price),
        status = VALUES(status), metadata = VALUES(metadata), synced_at = NOW()");
    $stmt->execute([
        'game_id' => $gameId,
        'provider_id' => $providerId,
        'product_code' => $code,
        'name' => $name,
        'base_price' => $basePrice,
        'selling_price' => $sellingPrice,
        'status' => $status,
        'metadata' => json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);
}

function sync_digiflazz_catalog(PDO $pdo): array
{
    $providerStmt = $pdo->prepare("SELECT id, status FROM providers WHERE code = 'DIGI' LIMIT 1");
    $providerStmt->execute();
    $provider = $providerStmt->fetch();
    if (!is_array($provider)) {
        throw new RuntimeException('Digiflazz provider is not seeded.');
    }
    if ($provider['status'] === 'DISABLED') {
        return ['provider' => 'DIGI', 'seen' => 0, 'mapped' => 0, 'skipped' => true];
    }

    $items = digiflazz_price_list();
    if ($items === []) {
        throw new RuntimeException('Digiflazz returned an empty catalog; existing products were left unchanged.');
    }
    $seen = 0;
    $mapped = 0;
    $validResponseItems = 0;
    $pdo->beginTransaction();
    try {
        catalog_sync_mark_provider_stale($pdo, (int) $provider['id']);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = catalog_sync_text($item['buyer_sku_code'] ?? '');
            $name = catalog_sync_text($item['product_name'] ?? '');
            $gameCode = catalog_sync_text($item['brand'] ?? '');
            $category = catalog_sync_text($item['category'] ?? '', 120);
            if ($code === '' || $name === '' || !is_numeric($item['price'] ?? null)) {
                continue;
            }
            $validResponseItems++;
            if (catalog_sync_is_excluded($name, $code)) {
                continue;
            }
            $basePrice = catalog_sync_price($item['price']);
            $available = ($item['buyer_product_status'] ?? false) === true
                && ($item['seller_product_status'] ?? false) === true;
            catalog_sync_upsert_provider_product($pdo, (int) $provider['id'], $gameCode, $code, $name, $category, $basePrice, $available, $item);
            $gameId = catalog_sync_game_id($pdo, (int) $provider['id'], $gameCode);
            if ($gameId !== null) {
                catalog_sync_upsert_store_product($pdo, (int) $provider['id'], $gameId, $code, $name, $basePrice, $available, $item);
                $mapped++;
            }
            $seen++;
        }
        if ($validResponseItems === 0) {
            throw new RuntimeException('Digiflazz catalog had no valid product records; refresh was rolled back.');
        }
        if ($mapped === 0) {
            throw new RuntimeException('No Digiflazz services matched active game mappings; refresh was rolled back to preserve the existing catalog.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['provider' => 'DIGI', 'seen' => $seen, 'mapped' => $mapped, 'skipped' => false];
}

function sync_vipayment_catalog(PDO $pdo): array
{
    $providerStmt = $pdo->prepare("SELECT id, status FROM providers WHERE code = 'VIPAY' LIMIT 1");
    $providerStmt->execute();
    $provider = $providerStmt->fetch();
    if (!is_array($provider)) {
        throw new RuntimeException('VIPayment provider is not seeded.');
    }
    if ($provider['status'] === 'DISABLED') {
        return ['provider' => 'VIPAY', 'seen' => 0, 'mapped' => 0, 'skipped' => true];
    }

    $tier = strtolower(trim((string) (getenv('VIPAYMENT_PRICE_TIER') ?: 'basic')));
    if (!in_array($tier, ['basic', 'premium', 'special'], true)) {
        throw new RuntimeException('VIPAYMENT_PRICE_TIER must be basic, premium, or special.');
    }
    $items = vipayment_game_services(null, null);
    if ($items === []) {
        throw new RuntimeException('VIPayment returned an empty catalog; existing products were left unchanged.');
    }
    $seen = 0;
    $mapped = 0;
    $validResponseItems = 0;
    $pdo->beginTransaction();
    try {
        catalog_sync_mark_provider_stale($pdo, (int) $provider['id']);
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = catalog_sync_text($item['code'] ?? '');
            $name = catalog_sync_text($item['name'] ?? '');
            $gameCode = catalog_sync_text($item['game'] ?? '');
            $category = $gameCode;
            $prices = $item['price'] ?? null;
            if ($code === '' || $name === '' || !is_array($prices) || !is_numeric($prices[$tier] ?? null)) {
                continue;
            }
            $validResponseItems++;
            if (catalog_sync_is_excluded($name, $code) || catalog_sync_is_excluded($gameCode, $code)) {
                continue;
            }
            $basePrice = catalog_sync_price($prices[$tier]);
            $available = strtolower(catalog_sync_text($item['status'] ?? '')) === 'available';
            catalog_sync_upsert_provider_product($pdo, (int) $provider['id'], $gameCode, $code, $name, $category, $basePrice, $available, $item);
            $gameId = catalog_sync_game_id($pdo, (int) $provider['id'], $gameCode);
            if ($gameId !== null) {
                // Keep server/region in raw metadata and the provider SKU, not the display name.
                // This lets catalog_products() group equivalent services and expose the cheapest available offer.
                catalog_sync_upsert_store_product($pdo, (int) $provider['id'], $gameId, $code, $name, $basePrice, $available, $item);
                $mapped++;
            }
            $seen++;
        }
        if ($validResponseItems === 0) {
            throw new RuntimeException('VIPayment catalog had no valid service records; refresh was rolled back.');
        }
        if ($mapped === 0) {
            throw new RuntimeException('No VIPayment services matched active game mappings; refresh was rolled back to preserve the existing catalog.');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['provider' => 'VIPAY', 'seen' => $seen, 'mapped' => $mapped, 'skipped' => false, 'tier' => $tier];
}
