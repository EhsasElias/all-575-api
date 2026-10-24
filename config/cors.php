<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // The Capacitor app runs from https://localhost (Android) or capacitor://localhost (iOS); Vite serves the web build in development.
    // Set CORS_ALLOWED_ORIGINS explicitly in every deployed environment.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173,https://localhost,capacitor://localhost',
    ))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Accept-Language', 'Authorization', 'Content-Type', 'Idempotency-Key', 'X-Requested-With'],
    'exposed_headers' => ['Retry-After'],
    'max_age' => 600,

    // Authentication is by bearer token, never by cookie, so credentials are not shared across origins.
    'supports_credentials' => false,
];
