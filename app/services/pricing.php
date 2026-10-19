<?php
declare(strict_types=1);

/** Apply one validated pricing rule to a supplier cost, in whole IDR rupiah. */
function pricing_calculate_profit(int $basePrice, string $profitType, float $profitValue): int
{
    if ($basePrice < 0 || !is_finite($profitValue) || $profitValue < 0
        || !in_array($profitType, ['FIXED', 'PERCENTAGE'], true)
        || ($profitType === 'PERCENTAGE' && $profitValue > 100)) {
        throw new InvalidArgumentException('Invalid pricing rule.');
    }
    $amount = $profitType === 'PERCENTAGE' ? $basePrice * $profitValue / 100 : $profitValue;
    return (int) round($amount, 0, PHP_ROUND_HALF_UP);
}

/** Pricing rules are loaded from the database. Amounts are whole IDR rupiah. */
function pricing_profit(PDO $pdo, int $basePrice): int
{
    if ($basePrice < 0) {
        throw new InvalidArgumentException('Base price cannot be negative.');
    }
    $stmt = $pdo->prepare("SELECT profit_type, profit_value FROM pricing_rules
        WHERE status='ACTIVE' AND min_amount <= :min_amount
          AND (max_amount IS NULL OR max_amount >= :max_amount)
        ORDER BY min_amount DESC, id DESC LIMIT 1");
    $stmt->execute(['min_amount' => $basePrice, 'max_amount' => $basePrice]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($rule)) {
        throw new RuntimeException('No active pricing rule matches this amount.');
    }
    return pricing_calculate_profit($basePrice, (string)$rule['profit_type'], (float)$rule['profit_value']);
}

function pricing_selling_price(PDO $pdo, int $basePrice): int
{
    return $basePrice + pricing_profit($pdo, $basePrice);
}

function pricing_admin_fee(int $sellingPrice): int
{
    if ($sellingPrice < 0) {
        throw new InvalidArgumentException('Selling price cannot be negative.');
    }
    return $sellingPrice <= 100000 ? 1000 : 2000;
}

function pricing_invoice_total(int $sellingPrice): int
{
    return $sellingPrice + pricing_admin_fee($sellingPrice);
}
