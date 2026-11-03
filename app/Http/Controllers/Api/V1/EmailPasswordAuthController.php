<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Actions\RecordConsents;
use App\Domain\Identity\Actions\RequestOtp;
use App\Domain\Identity\Actions\VerifyOtp;
use App\Domain\Identity\Enums\OtpChannel;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * Native email/password credentials backed by MySQL and Sanctum.
 * Email ownership is proved by the existing single-use OTP workflow.
 */
final class EmailPasswordAuthController extends Controller
{
    public function register(Request $request, RecordConsents $recordConsents, RequestOtp $requestOtp): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128', Password::min(12)],
            'consents' => ['required', 'array'],
            'consents.terms_version' => ['required', 'string', 'max:20'],
            'consents.privacy_version' => ['required', 'string', 'max:20'],
            'consents.marketing' => ['sometimes', 'boolean'],
        ]);
        if ($data['consents']['terms_version'] !== config('store.terms_version')
            || $data['consents']['privacy_version'] !== config('store.privacy_version')) {
            throw new DomainRuleViolation('consent_required', 'Accept current Terms and Privacy Policy.', 422);
        }
        $email = strtolower(trim($data['email']));
        if (User::query()->where('email', $email)->exists()) {
            throw new DomainRuleViolation('email_exists', 'This email is already registered. Use sign in or account recovery.', 409);
        }

        // Account creation and OTP delivery must succeed or fail together.
        // No unverified orphan account remains if the mail provider rejects delivery.
        $challenge = DB::transaction(function () use ($email, $data, $recordConsents, $request, $requestOtp): OtpChallenge {
            $user = User::query()->create(['email' => $email, 'locale' => app()->getLocale(), 'is_active' => true]);
            $user->forceFill(['password' => Hash::make($data['password'])])->save();
            $recordConsents->handle($user, $data['consents'], app()->getLocale(), $request->ip());
            return $requestOtp->handle(OtpChannel::Email, $email, app()->getLocale(), $request->ip());
        });

        return response()->json(['data' => [
            'challenge_id' => $challenge->id,
            'expires_in' => (int) config('store.otp.ttl_seconds'),
            'resend_after' => (int) config('store.otp.resend_cooldown_seconds'),
        ]], 202);
    }

    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:128'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $user = User::query()->where('email', strtolower(trim($data['email'])))->first();
        // Run an equivalent hash computation even for unknown accounts.
        $hash = $user?->password ?: Hash::make('unavailable-password-placeholder');
        if (! Hash::check($data['password'], $hash) || ! $user || ! $user->is_active || ! $user->email_verified_at) {
            throw new DomainRuleViolation('invalid_credentials', 'Invalid credentials or unverified email. Use email code verification.', 401);
        }
        if (! $user->hasAcceptedCurrentLegalVersions()) {
            throw new DomainRuleViolation('consent_required', 'Accept current Terms and Privacy using email OTP.', 422, [
                'terms_version' => config('store.terms_version'),
                'privacy_version' => config('store.privacy_version'),
            ]);
        }
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($data['device_name'] ?? 'Email login', ['customer'], now()->addDays((int) config('store.sessions.ttl_days')));
        $audit->record('auth.login', $user->id, $user, ['method' => 'email_password'], $request->ip());

        return response()->json(['data' => [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'is_new_user' => false,
            'user' => new UserResource($user->refresh()),
        ]]);
    }

    public function resetPassword(Request $request, VerifyOtp $verifyOtp): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'string', 'size:26'],
            'code' => ['required', 'string', 'digits:6'],
            'password' => ['required', 'string', 'max:128', Password::min(12)],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);
        $challenge = OtpChallenge::query()->whereKey($data['challenge_id'])->where('channel', OtpChannel::Email->value)->first();
        $user = $challenge ? User::query()->where('email', $challenge->destination)->first() : null;
        if (! $user || ! $user->email_verified_at || ! $user->is_active) {
            throw new DomainRuleViolation('otp_expired', 'Request a new verification code.', 422);
        }
        $login = $verifyOtp->handle(
            $data['challenge_id'], $data['code'], $data['device_name'] ?? 'Password recovery',
            app()->getLocale(), null, $request->ip()
        );
        // Revoke old credentials/sessions after a verified recovery. The newly issued token remains valid.
        DB::transaction(function () use ($user, $data, $login): void {
            $user->forceFill(['password' => Hash::make($data['password'])])->save();
            $user->tokens()->where('id', '!=', $login->token->accessToken->id)->delete();
        });
        return response()->json(['data' => [
            'token' => $login->token->plainTextToken,
            'user' => new UserResource($user->refresh()),
            'is_new_user' => false,
        ]]);
    }

}
