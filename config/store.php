<?php

return [
    'currency' => 'SAR',

    // 15% Saudi VAT, in basis points (1500 = 15.00%).
    'vat_rate_basis_points' => (int) env('STORE_VAT_RATE_BASIS_POINTS', 1500),

    // Bump these when the legal text changes; accepted versions are stored per user.
    'terms_version' => env('STORE_TERMS_VERSION', '2026-10-01'),
    'privacy_version' => env('STORE_PRIVACY_VERSION', '2026-10-01'),

    'otp' => [
        // log: writes the code to the application log (local and testing only).
        // unconfigured: refuses to send until an SMS/email provider is contracted.
        'driver' => env('OTP_DRIVER', 'unconfigured'),
        'length' => 6,
        'ttl_seconds' => 300,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
    ],

    'cart' => [
        'max_quantity_per_line' => 20,
        'max_lines' => 50,
    ],

    'quote' => [
        'ttl_minutes' => 10,
    ],

    'sessions' => [
        // Sanctum tokens: a revoked or expired device must log in again with a new OTP.
        'ttl_days' => (int) env('SESSION_TTL_DAYS', 30),
    ],
];
