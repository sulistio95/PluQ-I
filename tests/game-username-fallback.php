<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/services/game_username_check.php';

function vipayment_nickname_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$cases = [
    ['mobile-legends', '12345678', '1234'],
    ['mobile-legends-region', '12345678', '1234'],
    ['free-fire', '123456789', ''],
    ['pubgm', '123456789', ''],
    ['valorant', 'player#tag', ''],
    ['genshin-impact', '123456789', 'Asia'],
    ['honkai-star-rail', '123456789', 'Asia'],
    ['pointblank', 'player123', ''],
];
foreach ($cases as [$code, $userId, $zone]) {
    $params = game_username_check_parameters($code, $userId, $zone);
    vipayment_nickname_test($params['type'] === 'get-nickname', "{$code} must use VIPayment get-nickname");
    vipayment_nickname_test($params['code'] === $code, "{$code} must preserve the documented nickname code");
    vipayment_nickname_test($params['target'] === $userId, "{$code} must send User ID in target");
    vipayment_nickname_test($params['additional_target'] === $zone, "{$code} must send Zone/Server in additional_target");
}

$rejected = false;
try {
    game_username_check_parameters('not-supported', '12345', '');
} catch (InvalidArgumentException) {
    $rejected = true;
}
vipayment_nickname_test($rejected, 'Unsupported codes must not be sent to VIPayment');

$emptyIdRejected = false;
try {
    game_username_check_parameters('free-fire', '', '');
} catch (InvalidArgumentException) {
    $emptyIdRejected = true;
}
vipayment_nickname_test($emptyIdRejected, 'Empty user IDs must be rejected before upstream request');

echo "VIPayment nickname lookup parameter tests passed.\n";
