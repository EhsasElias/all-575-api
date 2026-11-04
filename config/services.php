<?php

return [

'geocoding' => [
        'provider' => env('GEOCODING_PROVIDER', 'nominatim'),
        'geoapify_key' => env('GEOAPIFY_API_KEY', ''),
        'url' => env('GEOCODING_REVERSE_URL', 'https://nominatim.openstreetmap.org/reverse'),
        // Optional second Nominatim-compatible HTTPS endpoint. Leave unset until contracted/hosted.
        'fallback_url' => env('GEOCODING_FALLBACK_REVERSE_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
