<?php
declare(strict_types=1);

function catalog_game_fields(PDO $pdo, string $slug): array
{
    $stmt = $pdo->prepare("SELECT gf.id,gf.field_key,gf.label,gf.field_type,gf.placeholder,gf.is_required,gf.validation_regex
        FROM game_fields gf INNER JOIN games g ON g.id=gf.game_id
        WHERE g.slug=:slug AND g.status='ACTIVE' AND gf.status='ACTIVE'
        ORDER BY gf.sort_order,gf.id");
    $stmt->execute(['slug' => $slug]);
    $fields = $stmt->fetchAll();
    if (!$fields) {
        return [];
    }

    $options = $pdo->prepare("SELECT option_value,option_label FROM game_field_options
        WHERE game_field_id=:field_id AND status='ACTIVE' ORDER BY sort_order,option_label");
    foreach ($fields as &$field) {
        $field['is_required'] = (bool) $field['is_required'];
        $field['options'] = [];
        if ($field['field_type'] === 'select') {
            $options->execute(['field_id' => $field['id']]);
            $field['options'] = $options->fetchAll();
        }
        unset($field['id']);
    }
    unset($field);
    return $fields;
}
