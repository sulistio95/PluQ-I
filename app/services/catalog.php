<?php
declare(strict_types=1);

/**
 * Read-only public catalog queries for PluQ-I.
 * Supplier sync and order placement are intentionally not exposed here.
 */

function catalog_games(PDO $pdo): array
{
    $sql = "SELECT g.id, g.slug, g.name, g.description, g.icon_url
            FROM games g
            WHERE g.status = 'ACTIVE'
            ORDER BY g.sort_order ASC, g.name ASC";
    return $pdo->query($sql)->fetchAll();
}

function catalog_banners(PDO $pdo): array
{
    $sql = "SELECT id, title, image_url, target_url
            FROM banners
            WHERE status = 'ACTIVE'
            ORDER BY sort_order ASC, id DESC";
    return $pdo->query($sql)->fetchAll();
}

function catalog_products(PDO $pdo, string $slug): array
{
    $sql = "SELECT
                p.id,
                p.name,
                p.provider_product_code AS code,
                p.base_price,
                p.selling_price,
                p.status,
                pr.code AS provider_code,
                pr.name AS provider_name,
                pr.balance,
                pr.reserve_percentage,
                CASE
                    WHEN p.status <> 'ACTIVE' THEN 0
                    WHEN pr.status <> 'ACTIVE' THEN 0
                    WHEN pr.balance IS NULL THEN 0
                    WHEN p.base_price > (pr.balance * (pr.reserve_percentage / 100)) THEN 0
                    ELSE 1
                END AS is_available
            FROM products p
            INNER JOIN games g ON g.id = p.game_id
            INNER JOIN providers pr ON pr.id = p.provider_id
            LEFT JOIN excluded_services ex
              ON ex.provider_id = p.provider_id
             AND ex.service_code = p.provider_product_code
            WHERE g.slug = :slug
              AND g.status = 'ACTIVE'
              AND ex.id IS NULL
              AND LOWER(CONCAT(p.name, ' ', p.provider_product_code)) NOT LIKE '%joki%'
              AND LOWER(CONCAT(p.name, ' ', p.provider_product_code)) NOT LIKE '%vilog%'
            ORDER BY p.selling_price ASC, p.name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['slug' => $slug]);
    $rows = $stmt->fetchAll();

    // Only the cheapest active offer for a same-named item is shown.
    // The supplier identity remains internal to the purchasing flow.
    $cheapest = [];
    foreach ($rows as $row) {
        $key = mb_strtolower(trim((string) $row['name']), 'UTF-8');
        if ($key === '') {
            continue;
        }
        if (!isset($cheapest[$key]) || (float) $row['selling_price'] < (float) $cheapest[$key]['selling_price']) {
            $cheapest[$key] = $row;
        } elseif ((float) $row['selling_price'] === (float) $cheapest[$key]['selling_price']
            && (int) $row['is_available'] > (int) $cheapest[$key]['is_available']) {
            $cheapest[$key] = $row;
        }
    }

    $result = array_values($cheapest);
    foreach ($result as &$item) {
        unset($item['provider_code'], $item['provider_name'], $item['balance'], $item['reserve_percentage']);
        $item['base_price'] = (float) $item['base_price'];
        $item['selling_price'] = (float) $item['selling_price'];
        $item['is_available'] = (bool) $item['is_available'];
    }
    unset($item);

    usort($result, static fn (array $a, array $b): int =>
        ($a['selling_price'] <=> $b['selling_price']) ?: strcasecmp((string) $a['name'], (string) $b['name'])
    );

    return $result;
}
