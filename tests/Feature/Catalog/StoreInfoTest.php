<?php

namespace Tests\Feature\Catalog;

use Tests\TestCase;

class StoreInfoTest extends TestCase
{
    public function test_the_app_can_read_the_platform_price_vat_rate_and_current_legal_versions_without_signing_in(): void
    {
        $this->getJson('/api/v1/store')
            ->assertOk()
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.price', ['gross' => 575, 'net' => 500, 'vat' => 75])
            ->assertJsonPath('data.vat_rate_basis_points', 1500)
            ->assertJsonPath('data.terms_version', config('store.terms_version'))
            ->assertJsonPath('data.privacy_version', config('store.privacy_version'));
    }
}
