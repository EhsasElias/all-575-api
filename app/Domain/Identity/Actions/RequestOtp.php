<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Contracts\OtpSender;
use App\Domain\Identity\Enums\OtpChannel;
use App\Domain\Identity\Models\OtpChallenge;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class RequestOtp
{
    public function __construct(private readonly OtpSender $sender) {}

    /** @param  string  $destination  already normalised (E.164 phone or lower-case email) */
    public function handle(OtpChannel $channel, string $destination, string $locale, ?string $ip): OtpChallenge
    {
        // The cooldown is checked, then a code is sent. Without a lock, parallel requests would all pass the check
        // together and each send a message (SMS cost, and one person's valid code invalidated by another request).
        $lock = Cache::lock('otp-request:'.hash('sha256', $channel->value.'|'.$destination), 15);

        if (! $lock->get()) {
            throw DomainRuleViolation::tooManyAttempts('otp_cooldown', 'A code is already being sent. Please wait.', 5);
        }

        try {
            return $this->issue($channel, $destination, $locale, $ip);
        } finally {
            $lock->release();
        }
    }

    private function issue(OtpChannel $channel, string $destination, string $locale, ?string $ip): OtpChallenge
    {
        $this->assertCooldownElapsed($channel, $destination);

        $code = $this->generateCode();

        return DB::transaction(function () use ($channel, $destination, $locale, $ip, $code): OtpChallenge {
            OtpChallenge::query()
                ->where('channel', $channel->value)
                ->where('destination', $destination)
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => now()]);

            $challenge = OtpChallenge::query()->create([
                'channel' => $channel,
                'destination' => $destination,
                'code_hash' => self::hash($code),
                'max_attempts' => config('store.otp.max_attempts'),
                'expires_at' => now()->addSeconds(config('store.otp.ttl_seconds')),
                'ip_address' => $ip,
            ]);

            // Inside the transaction: if the provider fails, no challenge is left behind.
            $this->sender->send($channel, $destination, $code, $locale);

            return $challenge;
        });
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function assertCooldownElapsed(OtpChannel $channel, string $destination): void
    {
        $cooldown = (int) config('store.otp.resend_cooldown_seconds');

        $latest = OtpChallenge::query()
            ->where('channel', $channel->value)
            ->where('destination', $destination)
            ->latest('created_at')
            ->first();

        if ($latest === null || $latest->created_at->addSeconds($cooldown)->isPast()) {
            return;
        }

        throw DomainRuleViolation::tooManyAttempts(
            'otp_cooldown',
            'Please wait before requesting another code.',
            max(1, (int) now()->diffInSeconds($latest->created_at->addSeconds($cooldown))),
        );
    }

    private function generateCode(): string
    {
        $length = (int) config('store.otp.length');

        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }
}
