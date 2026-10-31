<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\FirebaseIdTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FirebaseLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.firebase.enabled', true);
        config()->set('services.firebase.project_id', 'market575-4eccd');
        $this->verified('uid-123', 'customer@example.com');
    }

    private function verified(string $uid, string $email): void
    {
        $fake = Mockery::mock(FirebaseIdTokenVerifier::class);
        $fake->shouldReceive('verify')->andReturn(['uid' => $uid, 'email' => $email]);
        app()->instance(FirebaseIdTokenVerifier::class, $fake);
    }

    private function requestBody(array $extra = []): array
    {
        return [
            'id_token' => 'fake-firebase-token',
            'device_name' => 'Browser',
            'consents' => [
                'terms_version' => config('store.terms_version'),
                'privacy_version' => config('store.privacy_version'),
                'marketing' => false,
            ],
        ] + $extra;
    }

    public function test_new_verified_email_needs_current_consents_before_a_user_is_created(): void
    {
        $this->postJson('/api/v1/auth/firebase/login', ['id_token' => 'fake-firebase-token'])
            ->assertUnprocessable()->assertJsonPath('code', 'consent_required');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_verified_firebase_email_creates_one_customer_and_issues_a_sanctum_session(): void
    {
        $result = $this->postJson('/api/v1/auth/firebase/login', $this->requestBody())
            ->assertCreated()
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonPath('data.user.email_verified', true)
            ->assertJsonPath('data.user.email', 'customer@example.com');

        $this->withToken($result->json('data.token'))->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.email', 'customer@example.com');

        $this->postJson('/api/v1/auth/firebase/login', ['id_token' => 'fake-firebase-token'])
            ->assertOk()->assertJsonPath('data.is_new_user', false);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_verified_otp_email_is_bound_to_firebase_without_duplication(): void
    {
        $user = User::factory()->create(['email' => 'customer@example.com', 'email_verified_at' => now()]);
        $this->postJson('/api/v1/auth/firebase/login', $this->requestBody())
            ->assertOk()->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'firebase_uid' => 'uid-123']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_never_claims_an_unverified_existing_email(): void
    {
        User::factory()->create(['email' => 'customer@example.com', 'email_verified_at' => null]);

        $this->postJson('/api/v1/auth/firebase/login', $this->requestBody())
            ->assertStatus(409)->assertJsonPath('code', 'account_link_required');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_never_changes_an_existing_firebase_binding_to_another_uid(): void
    {
        User::factory()->create(['email' => 'customer@example.com', 'firebase_uid' => 'uid-previous', 'email_verified_at' => now()]);

        $this->postJson('/api/v1/auth/firebase/login', $this->requestBody())
            ->assertStatus(409)->assertJsonPath('code', 'account_link_required');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_account_cannot_receive_session(): void
    {
        User::factory()->inactive()->create(['email' => 'customer@example.com', 'email_verified_at' => now()]);

        $this->postJson('/api/v1/auth/firebase/login', $this->requestBody())
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_feature_switch_defaults_to_disabled(): void
    {
        config()->set('services.firebase.enabled', false);

        $this->postJson('/api/v1/auth/firebase/login', $this->requestBody())
            ->assertStatus(503)->assertJsonPath('code', 'firebase_unavailable');
        $this->assertDatabaseCount('users', 0);
    }
}
