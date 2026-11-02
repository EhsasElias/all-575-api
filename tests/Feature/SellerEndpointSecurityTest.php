<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SellerEndpointSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_write_endpoints_reject_unauthenticated_requests(): void
    {
        $endpoints = [
            ['post', '/api/v1/seller/products'],
            ['patch', '/api/v1/seller/products/1'],
            ['post', '/api/v1/seller/products/1/variants'],
            ['post', '/api/v1/seller/products/1/submit'],
            ['post', '/api/v1/seller/products/1/image'],
            ['post', '/api/v1/seller/products/import'],
            ['patch', '/api/v1/seller/stock/1'],
        ];

        foreach ($endpoints as [$method, $uri]) {
            $this->json(strtoupper($method), $uri)->assertUnauthorized();
        }
    }

    public function test_seller_read_endpoints_reject_unauthenticated_requests(): void
    {
        foreach ([
            '/api/v1/seller/dashboard',
            '/api/v1/seller/products',
            '/api/v1/seller/stock',
            '/api/v1/seller/orders',
            '/api/v1/seller/orders/export.csv',
        ] as $uri) {
            $this->getJson($uri)->assertUnauthorized();
        }
    }
}
