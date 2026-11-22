<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DeliveryVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_request_customer_proof_or_complete_courier_job(): void
    {
        $this->postJson('/api/v1/me/suborders/01ARZ3NDEKTSV4RRFFQ69G5FAV/delivery-code')
            ->assertUnauthorized();
        $this->postJson('/api/v1/courier/jobs/1/deliver', ['delivery_code' => '123456'])
            ->assertUnauthorized();
    }

    public function test_buyer_does_not_receive_code_for_unknown_order(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer']);

        $this->postJson('/api/v1/me/suborders/01ARZ3NDEKTSV4RRFFQ69G5FAV/delivery-code')
            ->assertNotFound();
    }

    public function test_user_without_courier_profile_cannot_complete_delivery(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer']);

        $this->postJson('/api/v1/courier/jobs/1/deliver', [
            'delivery_code' => '123456',
        ])->assertNotFound();
    }

    public function test_delivery_proof_requires_six_digit_code(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['customer']);

        $this->postJson('/api/v1/courier/jobs/1/deliver', [
            'delivery_code' => 'abc',
        ])->assertUnprocessable()->assertJsonValidationErrors('delivery_code');
    }
}
