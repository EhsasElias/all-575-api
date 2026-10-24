<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_the_account(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_an_inactive_user_is_blocked_even_with_a_valid_token(): void
    {
        $this->actingAsCustomer(User::factory()->inactive()->create());

        $this->getJson('/api/v1/me')->assertForbidden()->assertJsonPath('code', 'account_inactive');
    }

    public function test_the_customer_can_update_name_and_language_but_not_the_phone(): void
    {
        $user = $this->actingAsCustomer();
        $phone = $user->phone;

        $this->patchJson('/api/v1/me', ['name' => 'Sara', 'locale' => 'en', 'phone' => '+966500000000'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sara')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.phone', $phone);
    }

    public function test_marketing_consent_is_off_until_granted_and_every_change_is_recorded(): void
    {
        $this->actingAsCustomer();

        $this->getJson('/api/v1/me')->assertJsonPath('data.consents.marketing', false);

        $this->putJson('/api/v1/me/consents/marketing', ['granted' => true])->assertOk()->assertJsonPath('data.consents.marketing', true);
        $this->putJson('/api/v1/me/consents/marketing', ['granted' => false])->assertOk()->assertJsonPath('data.consents.marketing', false);

        $this->assertDatabaseCount('consents', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'consent.marketing']);
    }

    public function test_the_user_sees_their_devices_and_can_revoke_another_one(): void
    {
        $user = User::factory()->create();
        $other = $user->createToken('Old iPad', ['customer']);
        $current = $user->createToken('Pixel 8', ['customer']);

        $list = $this->withToken($current->plainTextToken)->getJson('/api/v1/me/sessions')->assertOk();
        $this->assertCount(2, $list->json('data'));
        $this->assertTrue(collect($list->json('data'))->firstWhere('device_name', 'Pixel 8')['is_current']);

        $this->withToken($current->plainTextToken)->deleteJson('/api/v1/me/sessions/'.$other->accessToken->id)->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.session_revoked']);
    }

    public function test_a_user_cannot_revoke_someone_elses_device(): void
    {
        $victim = User::factory()->create()->createToken('Victim phone', ['customer']);
        $attacker = User::factory()->create()->createToken('Attacker phone', ['customer']);

        $this->withToken($attacker->plainTextToken)->deleteJson('/api/v1/me/sessions/'.$victim->accessToken->id)->assertNotFound();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $victim->accessToken->id]);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('Pixel 8', ['customer']);
        $other = $user->createToken('Old iPad', ['customer']);

        $this->withToken($current->plainTextToken)->deleteJson('/api/v1/auth/session')->assertNoContent();

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $current->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $token = User::factory()->create()->createToken('Pixel 8', ['customer'], now()->addDay());

        $this->travel(2)->days();

        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_token_without_the_customer_ability_is_refused(): void
    {
        $token = User::factory()->create()->createToken('Other app', ['seller']);

        $this->withToken($token->plainTextToken)->getJson('/api/v1/me')->assertForbidden();
    }
}
