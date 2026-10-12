<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SellerApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role),
            'email' => "{$role}@example.com",
            'password' => 'password',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    public function test_customer_can_submit_application_and_admin_can_see_it(): void
    {
        $customer = $this->createUser('customer');
        Sanctum::actingAs($customer);

        $payload = [
            'store_name_ar' => 'متجر جديد',
            'store_name_en' => 'New Store',
            'phone' => '0501234567',
        ];

        $response = $this->postJson('/api/v1/seller-applications', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.user_id', $customer->id)
            ->assertJsonPath('data.email', $customer->email);

        $applicationId = $response->json('data.id');
        $this->assertDatabaseHas('seller_applications', $payload + ['id' => $applicationId]);
        $this->getJson('/api/v1/seller-applications')
            ->assertOk()->assertJsonPath('data.0.id', $applicationId);

        $this->postJson('/api/v1/seller-applications', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You already have a pending seller application.');
        $this->assertDatabaseCount('seller_applications', 1);

        Sanctum::actingAs($this->createUser('admin'));
        $this->getJson('/api/v1/admin/seller-applications')
            ->assertOk()->assertJsonPath('data.0.id', $applicationId);
    }

    public function test_old_form_payload_is_rejected_with_missing_store_name_errors(): void
    {
        Sanctum::actingAs($this->createUser('customer'));

        $this->postJson('/api/v1/seller-applications', [
            'storeName' => 'New Store',
            'phone' => '0501234567',
            'category' => 'food',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['store_name_ar', 'store_name_en']);

        $this->assertDatabaseCount('seller_applications', 0);
    }

    public function test_guest_cannot_submit_application(): void
    {
        $this->postJson('/api/v1/seller-applications', [
            'store_name_ar' => 'متجر جديد',
            'store_name_en' => 'New Store',
            'phone' => '0501234567',
        ])->assertUnauthorized();
    }
}
