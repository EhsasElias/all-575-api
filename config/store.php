<?php

return [
    'product_price' => (float) env(
        'STORE_PRODUCT_PRICE',
        5.75
    ),

    'delivery_fee' => (float) env(
        'STORE_DELIVERY_FEE',
        0
    ),
];
