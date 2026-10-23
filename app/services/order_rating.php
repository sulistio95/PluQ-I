<?php
declare(strict_types=1);

/**
 * Validate CSRF token against the dedicated customer-rating session token.
 */
function order_rating_csrf_valid(mixed $token, mixed $sessionToken): bool
{
    return is_string($token)
        && $token !== ''
        && is_string($sessionToken)
        && $sessionToken !== ''
        && hash_equals($sessionToken, $token);
}

/** Ratings are allowed only for paid orders with a final customer-visible outcome. */
function order_rating_is_eligible(string $paymentStatus, string $orderStatus): bool
{
    return $paymentStatus === 'PAID'
        && in_array($orderStatus, ['SUCCESS', 'FAILED', 'REFUNDED'], true);
}

/**
 * Validate customer input without writing to the database.
 * @return array{rating:?int,reason:?string,comment:?string,error:?string}
 */
function order_rating_validate_submission(mixed $rawRating, mixed $rawReason, mixed $rawComment): array
{
    $allowedReasons = ['FAST_AND_SMOOTH', 'SLOW_PROCESS', 'ITEM_NOT_RECEIVED', 'ORDER_FAILED', 'SUPPORT_EXPERIENCE', 'OTHER'];
    $rating = filter_var($rawRating, FILTER_VALIDATE_INT);
    $reason = is_string($rawReason) ? $rawReason : '';
    $comment = is_string($rawComment) ? trim($rawComment) : '';

    if ($rating === false || $rating < 1 || $rating > 5) {
        return ['rating'=>null,'reason'=>null,'comment'=>null,'error'=>'Rating harus berupa angka 1 sampai 5.'];
    }
    if (!in_array($reason, $allowedReasons, true)) {
        return ['rating'=>null,'reason'=>null,'comment'=>null,'error'=>'Pilih alasan rating yang tersedia.'];
    }
    if (strlen($comment) > 1000) {
        return ['rating'=>null,'reason'=>null,'comment'=>null,'error'=>'Komentar maksimal 1000 byte.'];
    }
    return ['rating'=>$rating,'reason'=>$reason,'comment'=>$comment !== '' ? $comment : null,'error'=>null];
}
