<?php

declare(strict_types=1);

return [
    // Explicit opt-in. Never put real credentials in source control.
    'seller_email' => env('DEMO_SELLER_EMAIL', ''),
];
