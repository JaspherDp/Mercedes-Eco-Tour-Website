<?php
declare(strict_types=1);

// The hosted back control can be used after payment. Share authoritative
// verification and destinations with the normal Return to Merchant route.
$paymentReturnCancelled = true;
require __DIR__ . '/payment-success.php';
