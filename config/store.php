<?php

return [
    'currency' => 'SAR',

    // Everything on the platform costs this, VAT included (roadmap decision D-05: always 5.75 SAR).
    // 5.75 is 5.00 + 0.75 VAT at 15%. Orders keep the price they were bought at, so changing it here never rewrites history.
    'price_halalas' => 575,

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

    'checkout' => [
        // How long unpaid items stay held. After this the order is cancelled and the stock released.
        'reservation_minutes' => (int) env('CHECKOUT_RESERVATION_MINUTES', 15),
        'order_number_prefix' => 'A575',
    ],

    'slots' => [
        'timezone' => 'Asia/Riyadh',
        'first_hour' => 9,
        'last_hour' => 23,
        'length_minutes' => 120,
        'capacity' => 20,
        'lead_minutes' => 60,
        'days_ahead' => 3,
    ],

    'payments' => [
        // fake: simulated provider for local and testing. unconfigured: refuses until a gateway is contracted (D-02).
        'driver' => env('PAYMENT_DRIVER', 'unconfigured'),
        'fake_webhook_secret' => env('FAKE_PAYMENT_WEBHOOK_SECRET', 'local-development-only'),
        'methods' => ['mada', 'card', 'apple_pay', 'cash_on_delivery'],
    ],

    'delivery_code' => [
        'length' => 6,
        'max_attempts' => 5,
        'ttl_hours' => 12,
    ],

    'reviews' => [
        'edit_window_days' => 7,
    ],

    'sessions' => [
        // Sanctum tokens: a revoked or expired device must log in again with a new OTP.
        'ttl_days' => (int) env('SESSION_TTL_DAYS', 30),
    ],
];
