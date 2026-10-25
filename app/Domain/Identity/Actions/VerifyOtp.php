<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\OtpChannel;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AuditLogger;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

final class VerifyOtp
{
    public function __construct(
        private readonly RecordConsents $recordConsents,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array{terms_version?: string, privacy_version?: string, marketing?: bool}|null  $consents */
    public function handle(
        string $challengeId,
        string $code,
        string $deviceName,
        string $locale,
        ?array $consents,
        ?string $ip,
    ): VerifiedLogin {
        // Committed on its own so a wrong guess keeps its attempt count even though we then throw.
        $failure = DB::transaction(fn (): ?DomainRuleViolation => $this->checkCode($challengeId, $code));

        if ($failure !== null) {
            throw $failure;
        }

        return DB::transaction(fn (): VerifiedLogin => $this->completeLogin($challengeId, $deviceName, $locale, $consents, $ip));
    }

    private function checkCode(string $challengeId, string $code): ?DomainRuleViolation
    {
        $challenge = OtpChallenge::query()->lockForUpdate()->find($challengeId);

        if ($challenge === null || ! $challenge->isUsable()) {
            return new DomainRuleViolation('otp_expired', 'This code is no longer valid. Request a new one.');
        }

        if (hash_equals($challenge->code_hash, RequestOtp::hash($code))) {
            return null;
        }

        $challenge->increment('attempts');

        if ($challenge->attemptsRemaining() === 0) {
            $challenge->forceFill(['invalidated_at' => now()])->save();

            return DomainRuleViolation::tooManyAttempts('otp_locked', 'Too many wrong codes. Request a new one.', 60);
        }

        return new DomainRuleViolation('otp_invalid', 'The code is incorrect.', 422, [
            'attempts_remaining' => $challenge->attemptsRemaining(),
        ]);
    }

    /** @param  array{terms_version?: string, privacy_version?: string, marketing?: bool}|null  $consents */
    private function completeLogin(string $challengeId, string $deviceName, string $locale, ?array $consents, ?string $ip): VerifiedLogin
    {
        $challenge = OtpChallenge::query()->lockForUpdate()->findOrFail($challengeId);

        // The code was checked in an earlier transaction. A parallel request with the same right code may have
        // used it since, and a one-time code must give out one token.
        if (! $challenge->isUsable()) {
            throw new DomainRuleViolation('otp_expired', 'This code is no longer valid. Request a new one.');
        }

        $user = $this->findUser($challenge);
        $isNewUser = $user === null;

        if ($isNewUser || ! $user->hasAcceptedCurrentLegalVersions()) {
            $this->assertCurrentLegalVersionsAccepted($consents);
        }

        $user ??= $this->createUser($challenge, $locale);

        if (! $user->is_active) {
            throw new DomainRuleViolation('account_inactive', 'This account is not active.', 403);
        }

        if ($consents !== null) {
            $this->recordConsents->handle($user, $consents, $locale, $ip);
        }

        $challenge->forceFill(['consumed_at' => now()])->save();
        $this->markVerified($user, $challenge);

        $token = $this->issueToken($user, $deviceName);
        $this->audit->record('auth.login', $user->id, $user, ['device' => $deviceName, 'new_user' => $isNewUser], $ip);

        return new VerifiedLogin($user->refresh(), $token, $isNewUser);
    }

    private function findUser(OtpChallenge $challenge): ?User
    {
        return User::query()->where($this->identityColumn($challenge), $challenge->destination)->first();
    }

    private function createUser(OtpChallenge $challenge, string $locale): User
    {
        $column = $this->identityColumn($challenge);

        try {
            return User::query()->create([$column => $challenge->destination, 'locale' => $locale, 'is_active' => true]);
        } catch (UniqueConstraintViolationException) {
            // The same person signed up in a parallel request a moment ago.
            return User::query()->where($column, $challenge->destination)->firstOrFail();
        }
    }

    private function identityColumn(OtpChallenge $challenge): string
    {
        return $challenge->channel === OtpChannel::Phone ? 'phone' : 'email';
    }

    /** @param  array{terms_version?: string, privacy_version?: string}|null  $consents */
    private function assertCurrentLegalVersionsAccepted(?array $consents): void
    {
        $accepted = ($consents['terms_version'] ?? null) === config('store.terms_version')
            && ($consents['privacy_version'] ?? null) === config('store.privacy_version');

        if (! $accepted) {
            throw new DomainRuleViolation('consent_required', 'Accept the current Terms and Privacy Policy to continue.', 422, [
                'terms_version' => config('store.terms_version'),
                'privacy_version' => config('store.privacy_version'),
            ]);
        }
    }

    private function markVerified(User $user, OtpChallenge $challenge): void
    {
        $column = $challenge->channel === OtpChannel::Phone ? 'phone_verified_at' : 'email_verified_at';

        $user->forceFill([$column => $user->{$column} ?? now(), 'last_login_at' => now()])->save();
    }

    private function issueToken(User $user, string $deviceName): NewAccessToken
    {
        return $user->createToken(
            $deviceName,
            ['customer'],
            now()->addDays((int) config('store.sessions.ttl_days')),
        );
    }
}
