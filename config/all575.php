<?php

return [
    'accounts' => [
        'admin' => [
            'email' => env('ALL575_ADMIN_EMAIL'),
            'password' => env('ALL575_ADMIN_PASSWORD'),
        ],
        'seller' => [
            'email' => env('ALL575_SELLER_EMAIL'),
            'password' => env('ALL575_SELLER_PASSWORD'),
        ],
        'customer' => [
            'email' => env('ALL575_CUSTOMER_EMAIL'),
            'password' => env('ALL575_CUSTOMER_PASSWORD'),
        ],
    ],
];
