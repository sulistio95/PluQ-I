<?php
declare(strict_types=1);

// Backward-compatible entry point.
// Keep all checkout validation in one implementation so legacy callers cannot bypass
// cheapest-provider selection, excluded-service filtering, or safe account-field mapping.
require_once __DIR__ . '/checkout_quote.php';
