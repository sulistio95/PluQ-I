<?php
declare(strict_types=1);

/**
 * Generate customer account inputs from the synchronized game catalog.
 *
 * The VIPayment services endpoint exposes service codes and game names, but
 * does not expose a formal per-game input schema. Use conservative game-name
 * rules for known zone-based titles; unknown titles receive only a User ID.
 * Never infer that an account check is supported unless an active VIPAY
 * service code exists for the game.
 */
function game_account_fields_for_game(string $slug, string $name): array
{
    $identity = strtolower(trim($slug . ' ' . $name));
    $fields = [[
        'field_key' => 'user_id',
        'label' => 'ID Game / User ID',
        'field_type' => 'text',
        'placeholder' => 'Masukkan ID akun game',
        'is_required' => true,
        'validation_regex' => '^[^\\s]{1,190}$',
    ]];

    $needsZone = preg_match('/mobile[\\s_-]*legends?|\\bmlbb\\b|genshin|honkai[\\s_-]*star[\\s_-]*rail|honkai[\\s_-]*impact|punishing[\\s_-]*gray[\\s_-]*raven|tower[\\s_-]*of[\\s_-]*fantasy|life[\\s_-]*after|identity[\\s_-]*v/i', $identity) === 1;
    $needsServer = !$needsZone && preg_match('/\\b(server|region)\\b/i', $identity) === 1;

    if ($needsZone) {
        $fields[] = [
            'field_key' => 'zone_id',
            'label' => 'Zone ID',
            'field_type' => 'text',
            'placeholder' => 'Masukkan Zone ID',
            'is_required' => true,
            'validation_regex' => '^[^\\s]{1,190}$',
        ];
    } elseif ($needsServer) {
        $fields[] = [
            'field_key' => 'server',
            'label' => 'Server',
            'field_type' => 'text',
            'placeholder' => 'Masukkan server',
            'is_required' => true,
            'validation_regex' => '^[^\\s]{1,190}$',
        ];
    }

    return $fields;
}

/** Return generated fields and whether VIPayment has a service code for nickname lookup. */
function catalog_game_account_schema(PDO $pdo, string $slug): array
{
    $stmt = $pdo->prepare("SELECT id,slug,name FROM games WHERE slug=:slug AND status='ACTIVE' LIMIT 1");
    $stmt->execute(['slug' => $slug]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) {
        throw new DomainException('Game tidak tersedia.');
    }

    $fields = game_account_fields_for_game((string)$game['slug'], (string)$game['name']);
    $check = $pdo->prepare("SELECT 1
        FROM products p
        INNER JOIN providers pr ON pr.id=p.provider_id
        WHERE p.game_id=:game_id AND p.status='ACTIVE' AND pr.code='VIPAY'
          AND pr.status IN ('ACTIVE','LOW_BALANCE') AND p.provider_product_code IS NOT NULL
          AND TRIM(p.provider_product_code)<>'' 
          AND LOWER(CONCAT(p.name,' ',p.provider_product_code)) NOT LIKE '%joki%'
          AND LOWER(CONCAT(p.name,' ',p.provider_product_code)) NOT LIKE '%vilog%'
        LIMIT 1");
    $check->execute(['game_id' => $game['id']]);

    return [
        'game' => ['slug' => (string)$game['slug'], 'name' => (string)$game['name']],
        'fields' => $fields,
        'username_check_available' => (bool)$check->fetchColumn(),
    ];
}

/** Compatibility wrapper used by the public GET endpoint. */
function catalog_game_fields(PDO $pdo, string $slug): array
{
    return catalog_game_account_schema($pdo, $slug)['fields'];
}
