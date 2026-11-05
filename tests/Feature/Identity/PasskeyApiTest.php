<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PasskeyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_passkeys_are_disabled_by_default(): void
    {
        config()->set('passkeys.api_enabled', false);

        $this->getJson('/api/v1/auth/passkeys/status')
            ->assertOk()->assertJsonPath('data.enabled', false);
        $this->postJson('/api/v1/auth/passkeys/options', [])->assertNotFound();
        $this->postJson('/api/v1/auth/passkeys/verify', [])->assertNotFound();
    }

    public function test_passkey_management_needs_an_authenticated_customer(): void
    {
        config()->set('passkeys.api_enabled', true);

        $this->getJson('/api/v1/me/passkeys')->assertUnauthorized();
        $this->postJson('/api/v1/me/passkeys/options', ['password' => 'x'])->assertUnauthorized();
    }

    public function test_only_verified_customers_can_enroll_passkeys(): void
    {
        config()->set('passkeys.api_enabled', true);
        $user = User::query()->create([
            'email' => 'passkey-test@example.com',
            'is_active' => true,
        ]);
        $user->forceFill(['password' => Hash::make('TestPassword2026!')])->save();
        $token = $user->createToken('test', ['customer']);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/me/passkeys/options', ['password' => 'TestPassword2026!'])
            ->assertForbidden();
    }
}
