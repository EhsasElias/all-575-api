<?php

namespace Tests\Feature\Identity;

use App\Domain\Identity\Contracts\OtpSender;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UnconfiguredOtpSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OtpLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+966512345678';

    private function legalConsents(array $extra = []): array
    {
        return ['terms_version' => config('store.terms_version'), 'privacy_version' => config('store.privacy_version')] + $extra;
    }

    private function requestChallenge(string $destination = '0512345678'): string
    {
        return $this->postJson('/api/v1/auth/otp/request', ['channel' => 'phone', 'destination' => $destination])
            ->assertAccepted()
            ->json('data.challenge_id');
    }

    public function test_it_sends_a_code_to_the_normalised_saudi_number_and_stores_only_a_hash(): void
    {
        $this->requestChallenge('٠٥١٢٣٤٥٦٧٨');

        $this->assertSame(self::PHONE, $this->otp->sent[0]['destination']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->otp->lastCode());
        $this->assertNotSame($this->otp->lastCode(), OtpChallenge::query()->firstOrFail()->code_hash);
    }

    public function test_it_rejects_a_number_that_is_not_a_saudi_mobile(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['channel' => 'phone', 'destination' => '0112345678'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination');
    }

    public function test_it_makes_the_user_wait_before_asking_for_another_code(): void
    {
        $this->requestChallenge();

        $this->postJson('/api/v1/auth/otp/request', ['channel' => 'phone', 'destination' => self::PHONE])
            ->assertStatus(429)
            ->assertJsonPath('code', 'otp_cooldown')
            ->assertHeader('Retry-After');
    }

    public function test_a_new_user_must_accept_the_current_terms_and_privacy_policy(): void
    {
        $challenge = $this->requestChallenge();

        $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $this->otp->lastCode()])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'consent_required')
            ->assertJsonPath('terms_version', config('store.terms_version'));

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_new_user_is_created_verified_and_receives_a_token_with_recorded_consents(): void
    {
        $challenge = $this->requestChallenge();

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challenge,
            'code' => $this->otp->lastCode(),
            'device_name' => 'Pixel 8',
            'consents' => $this->legalConsents(['marketing' => false]),
        ])->assertCreated()
            ->assertJsonPath('data.is_new_user', true)
            ->assertJsonPath('data.user.phone', self::PHONE)
            ->assertJsonPath('data.user.phone_verified', true)
            ->assertJsonPath('data.user.consents.marketing', false)
            ->assertJsonPath('data.user.consents.needs_update', false);

        $user = User::query()->where('phone', self::PHONE)->firstOrFail();
        $this->assertDatabaseHas('consents', ['user_id' => $user->id, 'type' => 'terms', 'version' => config('store.terms_version')]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'auth.login']);

        $this->withToken($response->json('data.token'))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_the_same_code_cannot_be_used_twice(): void
    {
        $challenge = $this->requestChallenge();
        $payload = ['challenge_id' => $challenge, 'code' => $this->otp->lastCode(), 'consents' => $this->legalConsents()];

        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertCreated();
        $this->postJson('/api/v1/auth/otp/verify', $payload)->assertUnprocessable()->assertJsonPath('code', 'otp_expired');
    }

    public function test_a_returning_user_logs_in_without_repeating_consent(): void
    {
        $this->requestChallenge();
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => OtpChallenge::query()->latest('created_at')->firstOrFail()->id,
            'code' => $this->otp->lastCode(),
            'consents' => $this->legalConsents(),
        ])->assertCreated();

        $this->travel(2)->minutes();
        $challenge = $this->requestChallenge();

        $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $this->otp->lastCode()])
            ->assertOk()
            ->assertJsonPath('data.is_new_user', false);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_wrong_codes_count_down_and_then_lock_the_challenge_even_for_the_right_code(): void
    {
        $challenge = $this->requestChallenge();
        $right = $this->otp->lastCode();
        $wrong = $right === '000000' ? '111111' : '000000';

        foreach ([4, 3, 2, 1] as $remaining) {
            $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $wrong])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'otp_invalid')
                ->assertJsonPath('attempts_remaining', $remaining);
        }

        $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $wrong])
            ->assertStatus(429)
            ->assertJsonPath('code', 'otp_locked');

        $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $right, 'consents' => $this->legalConsents()])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'otp_expired');
    }

    public function test_an_expired_code_is_refused(): void
    {
        $challenge = $this->requestChallenge();
        $code = $this->otp->lastCode();

        $this->travel(6)->minutes();

        $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $code, 'consents' => $this->legalConsents()])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'otp_expired');
    }

    public function test_a_new_code_invalidates_the_previous_one(): void
    {
        $first = $this->requestChallenge();
        $firstCode = $this->otp->lastCode();

        $this->travel(2)->minutes();
        $this->requestChallenge();

        $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $first, 'code' => $firstCode, 'consents' => $this->legalConsents()])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'otp_expired');
    }

    public function test_an_inactive_account_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['phone' => self::PHONE]);
        $challenge = $this->requestChallenge();

        $this->postJson('/api/v1/auth/otp/verify', ['challenge_id' => $challenge, 'code' => $this->otp->lastCode(), 'consents' => $this->legalConsents()])
            ->assertForbidden()
            ->assertJsonPath('code', 'account_inactive');
    }

    public function test_it_refuses_to_send_codes_until_a_provider_is_configured(): void
    {
        $this->app->forgetInstance(OtpSender::class);
        $this->app->bind(OtpSender::class, UnconfiguredOtpSender::class);

        $this->postJson('/api/v1/auth/otp/request', ['channel' => 'phone', 'destination' => self::PHONE])
            ->assertStatus(503)
            ->assertJsonPath('code', 'otp_delivery_unavailable');

        $this->assertDatabaseCount('otp_challenges', 0);
    }
}
