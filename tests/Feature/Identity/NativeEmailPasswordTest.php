<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Contracts\OtpSender;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\UnconfiguredOtpSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class NativeEmailPasswordTest extends TestCase
{
    use RefreshDatabase;

    private function consent(): array
    {
        return [
            'terms_version' => config('store.terms_version'),
            'privacy_version' => config('store.privacy_version'),
            'marketing' => false,
        ];
    }

    private function register(): string
    {
        $response = $this->postJson('/api/v1/auth/email/register', [
            'email' => 'Seller@Example.com',
            'password' => 'VerySecurePassword2026!',
            'consents' => $this->consent(),
        ])->assertAccepted();

        return $response->json('data.challenge_id');
    }

    public function test_new_email_account_requires_otp_verification_before_password_login(): void
    {
        $challenge = $this->register();
        $user = User::query()->where('email', 'seller@example.com')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('VerySecurePassword2026!', $user->password));
        $this->assertNotSame('VerySecurePassword2026!', $user->password);

        $credentials = ['email' => 'seller@example.com', 'password' => 'VerySecurePassword2026!'];
        $this->postJson('/api/v1/auth/email/login', $credentials)->assertUnauthorized();

        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challenge,
            'code' => $this->otp->lastCode(),
        ])->assertOk()->assertJsonPath('data.user.email_verified', true);

        $reply = $this->postJson('/api/v1/auth/email/login', $credentials)
            ->assertOk()
            ->assertJsonPath('data.user.email', 'seller@example.com');

        $this->withToken($reply->json('data.token'))->getJson('/api/v1/me')->assertOk();
    }

    public function test_invalid_password_is_rejected(): void
    {
        $challenge = $this->register();
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challenge, 'code' => $this->otp->lastCode(),
        ])->assertOk();

        $this->postJson('/api/v1/auth/email/login', [
            'email' => 'seller@example.com', 'password' => 'not-the-right-password',
        ])->assertUnauthorized();
    }

    public function test_password_reset_requires_a_new_valid_email_otp(): void
    {
        $challenge = $this->register();
        $this->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challenge, 'code' => $this->otp->lastCode(),
        ])->assertOk();

        $this->travel(2)->minutes();
        $resetChallenge = $this->postJson('/api/v1/auth/otp/request', [
            'channel' => 'email', 'destination' => 'seller@example.com',
        ])->assertAccepted()->json('data.challenge_id');

        $this->postJson('/api/v1/auth/email/password-reset', [
            'challenge_id' => $resetChallenge,
            'code' => '999999' === $this->otp->lastCode() ? '000000' : '999999',
            'password' => 'AnotherSecurePassword2026!',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/email/password-reset', [
            'challenge_id' => $resetChallenge,
            'code' => $this->otp->lastCode(),
            'password' => 'AnotherSecurePassword2026!',
        ])->assertOk();

        $this->postJson('/api/v1/auth/email/login', [
            'email' => 'seller@example.com', 'password' => 'VerySecurePassword2026!',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/auth/email/login', [
            'email' => 'seller@example.com', 'password' => 'AnotherSecurePassword2026!',
        ])->assertOk();
    }

    public function test_registration_does_not_leave_a_user_if_otp_delivery_is_unconfigured(): void
    {
        $this->app->forgetInstance(OtpSender::class);
        $this->app->bind(OtpSender::class, UnconfiguredOtpSender::class);

        $this->postJson('/api/v1/auth/email/register', [
            'email' => 'seller@example.com',
            'password' => 'VerySecurePassword2026!',
            'consents' => $this->consent(),
        ])->assertStatus(503);

        $this->assertDatabaseCount('users', 0);
    }
}
