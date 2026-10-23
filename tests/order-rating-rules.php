<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/services/order_rating.php';

function rating_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

rating_test(order_rating_is_eligible('PAID','SUCCESS'), 'Paid successful order should be eligible');
rating_test(order_rating_is_eligible('PAID','FAILED'), 'Paid failed order should be eligible for feedback');
rating_test(order_rating_is_eligible('PAID','REFUNDED'), 'Paid refunded order should be eligible for feedback');
rating_test(!order_rating_is_eligible('PENDING','SUCCESS'), 'Unpaid order must not be eligible');
rating_test(!order_rating_is_eligible('PAID','PROCESSING'), 'Processing order must not be eligible');
rating_test(!order_rating_is_eligible('PAID','MANUAL_REVIEW'), 'Manual review order must not be eligible');

$valid = order_rating_validate_submission('5','FAST_AND_SMOOTH','  Proses lancar  ');
rating_test($valid['error'] === null && $valid['rating'] === 5, 'Valid five-star rating should pass');
rating_test($valid['comment'] === 'Proses lancar', 'Comment should be trimmed');
rating_test(order_rating_validate_submission('0','OTHER','')['error'] !== null, 'Rating below one must fail');
rating_test(order_rating_validate_submission('6','OTHER','')['error'] !== null, 'Rating above five must fail');
rating_test(order_rating_validate_submission('3','NOT_A_REASON','')['error'] !== null, 'Unknown reason must fail');
rating_test(order_rating_validate_submission('4','OTHER',str_repeat('x',1001))['error'] !== null, 'Oversized comment must fail');
rating_test(order_rating_validate_submission(['5'],'OTHER','')['error'] !== null, 'Array input must fail validation safely');

echo "PASS: order rating eligibility and input validation\n";
