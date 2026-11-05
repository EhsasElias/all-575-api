<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Stateless Sanctum + WebAuthn API for the browser SPA.
 *
 * The Laravel passkeys package verifies signatures, origin, RP ID and user verification.
 * Cache entries expire and are consumed once under a cross-request cache lock.
 * The package's default cookie/session routes are disabled in AppServiceProvider.
 */
final class PasskeyController extends Controller
{
    private function enabled(): void
    {
        abort_unless(config('passkeys.api_enabled') === true, 404);
    }

    private function requireActiveVerified(User $user): void
    {
        abort_unless($user->is_active && $user->email_verified_at && $user->hasAcceptedCurrentLegalVersions(), 403);
    }

    private function requirePassword(User $user, string $password): void
    {
        if (! $user->password || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Current password is incorrect.']);
        }
    }

    private function issue(string $purpose, object $options, ?int $userId = null): array
    {
        $id = Str::random(48);
        Cache::put('web-passkey:'.$id, [
            'purpose' => $purpose,
            'user_id' => $userId,
            'options' => WebAuthn::toJson($options),
        ], now()->addMinutes(2));

        return [
            'challenge_id' => $id,
            'options' => WebAuthn::toBrowserArray($options),
        ];
    }

    private function consume(string $id, string $purpose, ?int $userId = null): string
    {
        $key = 'web-passkey:'.$id;
        $entry = Cache::lock('web-passkey-lock:'.$id, 10)->block(3, static fn () => Cache::pull($key));
        if (! is_array($entry)
            || ($entry['purpose'] ?? null) !== $purpose
            || ($entry['user_id'] ?? null) !== $userId
            || ! is_string($entry['options'] ?? null)) {
            throw ValidationException::withMessages(['credential' => 'The passkey challenge expired. Try again.']);
        }

        return $entry['options'];
    }

    private function credential(array $credential): PublicKeyCredential
    {
        try {
            return WebAuthn::fromJson(json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['credential' => 'Invalid passkey response.']);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $this->enabled();
        $user = $request->user();
        $this->requireActiveVerified($user);

        return response()->json(['data' => $user->passkeys()->orderByDesc('id')
            ->get(['id', 'name', 'created_at', 'last_used_at'])]);
    }

    public function registrationOptions(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $this->enabled();
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        $this->requireActiveVerified($user);
        $this->requirePassword($user, $data['password']);

        return response()->json(['data' => $this->issue('register', $generate($user), (int) $user->id)]);
    }

    public function register(Request $request, StorePasskey $store): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'size:48'],
            'name' => ['required', 'string', 'max:100'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.response' => ['required', 'array'],
            'credential.type' => ['required', 'in:public-key'],
        ]);
        $user = $request->user();
        $this->requireActiveVerified($user);
        $serialized = $this->consume($data['challenge_id'], 'register', (int) $user->id);
        $options = WebAuthn::fromJson($serialized, PublicKeyCredentialCreationOptions::class);
        $passkey = $store($user, $data['name'], $this->credential($data['credential']), $options);

        return response()->json(['data' => ['id' => $passkey->id, 'name' => $passkey->name]], 201);
    }

    public function destroy(Request $request, int $passkey): JsonResponse
    {
        $this->enabled();
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();
        $this->requireActiveVerified($user);
        $this->requirePassword($user, $data['password']);
        $credential = $user->passkeys()->whereKey($passkey)->firstOrFail();
        $credential->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function loginOptions(GenerateVerificationOptions $generate): JsonResponse
    {
        $this->enabled();

        return response()->json(['data' => $this->issue('login', $generate())]);
    }

    public function login(Request $request, VerifyPasskey $verify, AuditLogger $audit): JsonResponse
    {
        $this->enabled();
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'size:48'],
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.response' => ['required', 'array'],
            'credential.type' => ['required', 'in:public-key'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $serialized = $this->consume($data['challenge_id'], 'login');
        $options = WebAuthn::fromJson($serialized, PublicKeyCredentialRequestOptions::class);
        $passkey = $verify($this->credential($data['credential']), $options);
        $user = $passkey->user;
        abort_unless($user instanceof User, 403);
        $this->requireActiveVerified($user);

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($data['device_name'] ?? 'Web passkey', ['customer'], now()->addDays((int) config('store.sessions.ttl_days')));
        $audit->record('auth.login', $user->id, $user, ['method' => 'passkey'], $request->ip());

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'is_new_user' => false,
            'user' => new UserResource($user->refresh()),
        ]]);
    }
}
