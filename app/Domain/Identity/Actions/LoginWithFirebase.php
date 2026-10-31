<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuditLogger;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\DB;

/**
 * A verified Firebase email may attach to an EXISTING verified email, but
 * never to an unverified email or to an account bound to another Firebase UID.
 */
class LoginWithFirebase
{
    public function __construct(
        private readonly RecordConsents $recordConsents,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{uid: string, email: string}  $identity
     * @param  array{terms_version?: string, privacy_version?: string, marketing?: bool}|null  $consents
     */
    public function handle(array $identity, string $deviceName, string $locale, ?array $consents, ?string $ip): VerifiedLogin
    {
        return DB::transaction(function () use ($identity, $deviceName, $locale, $consents, $ip): VerifiedLogin {
            $user = User::query()->where('firebase_uid', $identity['uid'])->lockForUpdate()->first();
            if ($user !== null && strcasecmp((string) $user->email, $identity['email']) !== 0) {
                throw new DomainRuleViolation('account_link_required', 'Email changed. Contact support to link this account.', 409);
            }

            if ($user === null) {
                $user = User::query()->where('email', $identity['email'])->lockForUpdate()->first();
                if ($user !== null && $user->firebase_uid !== null && $user->firebase_uid !== $identity['uid']) {
                    throw new DomainRuleViolation('account_link_required', 'This email is linked to another account.', 409);
                }
                if ($user !== null && $user->email_verified_at === null) {
                    throw new DomainRuleViolation('account_link_required', 'Verify the existing account before linking it.', 409);
                }
            }

            $isNewUser = $user === null;

            if ($isNewUser || $user->hasAcceptedCurrentLegalVersions() === false) {
                if (($consents['terms_version'] ?? null) !== config('store.terms_version')
                    || ($consents['privacy_version'] ?? null) !== config('store.privacy_version')) {
                    throw new DomainRuleViolation('consent_required', 'Accept the current Terms and Privacy Policy to continue.', 422, [
                        'terms_version' => config('store.terms_version'),
                        'privacy_version' => config('store.privacy_version'),
                    ]);
                }
            }

            $user ??= User::query()->create([
                'email' => $identity['email'],
                'email_verified_at' => now(),
                'locale' => $locale,
                'is_active' => true,
            ]);

            if ($user->is_active === false) {
                throw new DomainRuleViolation('account_inactive', 'This account is not active.', 403);
            }

            if ($user->firebase_uid === null) {
                $user->forceFill(['firebase_uid' => $identity['uid']])->save();
            }

            if ($consents !== null) {
                $this->recordConsents->handle($user, $consents, $locale, $ip);
            }

            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now(), 'last_login_at' => now()])->save();
            $token = $user->createToken($deviceName, ['customer'], now()->addDays((int) config('store.sessions.ttl_days')));
            $this->audit->record('auth.login', $user->id, $user, ['device' => $deviceName, 'new_user' => $isNewUser, 'method' => 'firebase_email'], $ip);

            return new VerifiedLogin($user->refresh(), $token, $isNewUser);
        });
    }
}
