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

    $needsZone = preg_match('/mobile[\\s_-]*legends?|\\bmlbb\\b|genshin[\\s_-]*impact|honkai[\\s_-]*star[\\s_-]*rail/i', $identity) === 1;
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
            'validation_regex' => '^.{1,190}$',
        ];
    }

    return $fields;
}

/** Only permit checkout for game schemas whose account target mapping is reviewed in code. */
function game_account_schema_is_supported(string $slug, string $name, array $fields): bool
{
    $identity = strtolower(trim($slug . ' ' . $name));
    $knownGame = preg_match('/mobile[\\s_-]*legends?|\\bmlbb\\b|honor[\\s_-]*of[\\s_-]*kings?|\\bhok\\b|free[\\s_-]*fire|pubg[\\s_-]*mobile|genshin|honkai[\\s_-]*(star[\\s_-]*rail|impact)|call[\\s_-]*of[\\s_-]*duty|\\bcod\\b|valorant|arena[\\s_-]*of[\\s_-]*valor|wild[\\s_-]*rift|blood[\\s_-]*strike|farlight[\\s_-]*84|sausage[\\s_-]*man|super[\\s_-]*sus/i', $identity) === 1;
    $hasUnmappedServer = count($fields) > 1 && ($fields[1]['field_key'] ?? '') === 'server';
    return $knownGame && !$hasUnmappedServer;
}

/**
 * Only allow nickname lookup when support for that game has been explicitly
 * reviewed. A top-up product code is not proof that get-nickname supports it.
 */
/**
 * Return the dedicated VIPayment Game Feature code for nickname lookup.
 * These are lookup codes, not top-up product codes.
 */
function game_account_username_check_code(string $slug, string $name): ?string
{
    $identity = strtolower(trim($slug . ' ' . $name));

    // Codes supplied by the user from VIPayment's Get Nickname feature list.
    if (preg_match('/mobile[\s_-]*legends?.*region|region.*mobile[\s_-]*legends?/i', $identity) === 1) {
        return 'mobile-legends-region';
    }
    if (preg_match('/mobile[\s_-]*legends?|\bmlbb\b/i', $identity) === 1) {
        return 'mobile-legends';
    }
    if (preg_match('/free[\s_-]*fire(?:[\s_-]*max)?/i', $identity) === 1) {
        return 'free-fire';
    }
    if (preg_match('/pubg[\s_-]*mobile|\bpubgm\b/i', $identity) === 1) {
        return 'pubgm';
    }
    if (preg_match('/genshin[\s_-]*impact/i', $identity) === 1) {
        return 'genshin-impact';
    }
    if (preg_match('/honkai[\s_-]*star[\s_-]*rail/i', $identity) === 1) {
        return 'honkai-star-rail';
    }
    if (preg_match('/valorant/i', $identity) === 1) {
        return 'valorant';
    }
    if (preg_match('/point[\s_-]*blank|\bpointblank\b/i', $identity) === 1) {
        return 'pointblank';
    }

    // Honor of Kings is absent from the supplied VIPayment lookup list.
    return null;
}

function game_account_username_check_supported(string $slug, string $name): bool
{
    return game_account_username_check_code($slug, $name) !== null;
}

/** Return generated fields and whether a reviewed VIPayment nickname lookup is available. */
function catalog_game_account_schema(PDO $pdo, string $slug): array
{
    $stmt = $pdo->prepare("SELECT id,slug,name FROM games WHERE slug=:slug AND status='ACTIVE' LIMIT 1");
    $stmt->execute(['slug' => $slug]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$game) {
        throw new DomainException('Game tidak tersedia.');
    }

    $fields = game_account_fields_for_game((string)$game['slug'], (string)$game['name']);
    $checkoutSupported = game_account_schema_is_supported((string)$game['slug'], (string)$game['name'], $fields);
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

    $usernameCheckSupported = game_account_username_check_supported((string)$game['slug'], (string)$game['name']);

    return [
        'game' => ['slug' => (string)$game['slug'], 'name' => (string)$game['name']],
        'fields' => $fields,
        'checkout_supported' => $checkoutSupported,
        'username_check_available' => $usernameCheckSupported && (bool)$check->fetchColumn(),
    ];
}

/** Compatibility wrapper used by the public GET endpoint. */
function catalog_game_fields(PDO $pdo, string $slug): array
{
    return catalog_game_account_schema($pdo, $slug)['fields'];
}
