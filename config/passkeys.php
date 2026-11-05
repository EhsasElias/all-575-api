<?php

return [
    'api_enabled' => (bool) env('PASSKEYS_ENABLED', false),
    'relying_party_id' => env('PASSKEY_RP_ID', 'market575.com'),
    'relying_party_name' => 'ALL 5.75',
    'allowed_origins' => [env('PASSKEY_ALLOWED_ORIGIN', 'https://market575.com')],
    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', env('APP_KEY')),
    'timeout' => 60000,
    'guard' => 'web',
    'middleware' => ['web'],
    'management_middleware' => ['password.confirm'],
    'throttle' => 'throttle:6,1',
    'redirect' => '/',
];
