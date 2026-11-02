<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SellerApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function application(): array
    {
        return [
            'legal_name' => 'Example Trading LLC',
            'commercial_registration' => '1010123456',
            'national_unified_number' => '7001234567',
            'contact_name' => 'Store owner',
            'contact_email' => 'seller@example.com',
            'contact_phone' => '+966500000000',
            'city' => 'Riyadh',
        ];
    }

    public function test_guest_cannot_apply(): void
    {
        $this->getJson('/api/v1/seller/application')->assertUnauthorized();
        $this->putJson('/api/v1/seller/application', $this->application())->assertUnauthorized();
    }

    public function test_verified_customer_can_save_draft_and_submit_without_becoming_seller(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAsCustomer($user);

        $this->putJson('/api/v1/seller/application', $this->application())
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->postJson('/api/v1/seller/application/submit')
            ->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->putJson('/api/v1/seller/application', $this->application())->assertStatus(409);
        $this->postJson('/api/v1/seller/session', ['device_name' => 'phone'])->assertForbidden();
        $this->assertDatabaseCount('seller_staff_access', 0);
    }

    public function test_customers_only_see_their_own_application(): void
    {
        $first = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAsCustomer($first);
        $this->putJson('/api/v1/seller/application', $this->application())->assertOk();

        $second = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAsCustomer($second);
        $this->getJson('/api/v1/seller/application')->assertOk()->assertJsonPath('data', null);
        $this->assertSame(1, DB::table('seller_applications')->count());
    }

    public function test_unverified_customer_cannot_apply(): void
    {
        $user = User::factory()->create(['phone_verified_at' => null, 'email_verified_at' => null]);
        $this->actingAsCustomer($user);
        $this->putJson('/api/v1/seller/application', $this->application())->assertForbidden();
    }
}
