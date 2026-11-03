<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\OtpSender;
use App\Domain\Identity\Enums\OtpChannel;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends Saudi SMS OTP through the contracted Taqnyat SMS API.
 * Email OTP is sent with Laravel Mail via MailOtpSender; no Firebase is used.
 */
final class TaqnyatOtpSender implements OtpSender
{
    public function __construct(private readonly MailOtpSender $emailSender) {}

    public function send(OtpChannel $channel, string $destination, string $code, string $locale): void
    {
        if ($channel === OtpChannel::Email) {
            $this->emailSender->send($channel, $destination, $code, $locale);
            return;
        }

        $token = (string) config('store.otp.taqnyat.token');
        $sender = (string) config('store.otp.taqnyat.sender');
        if ($token === '' || $sender === '') {
            throw new DomainRuleViolation('otp_delivery_unavailable', 'SMS gateway credentials or sender name are missing.', 503);
        }

        $recipient = ltrim($destination, '+');
        if (preg_match('/^9665[0-9]{8}$/D', $recipient) !== 1) {
            throw new DomainRuleViolation('otp_delivery_unavailable', 'Invalid Saudi mobile destination.', 503);
        }

        $body = $locale === 'ar'
            ? "رمز التحقق الخاص بك لتطبيق ALL 5.75: {$code}. لا تشارك الرمز مع أحد."
            : "Your ALL 5.75 verification code is {$code}. Do not share it.";

        try {
            $reply = Http::withToken($token)->acceptJson()->timeout(8)
                ->post('https://api.taqnyat.sa/v1/messages', [
                    'recipients' => [$recipient],
                    'body' => $body,
                    'sender' => $sender,
                ]);
            $accepted = $reply->json('accepted');
            if (! $reply->successful()
                || (int) $reply->json('statusCode') !== 201
                || ! is_string($accepted)
                || ! str_contains($accepted, $recipient)) {
                throw new \RuntimeException('SMS gateway did not accept recipient');
            }
        } catch (Throwable) {
            // Do not log OTP codes, bearer tokens or the full gateway response.
            throw new DomainRuleViolation('otp_delivery_unavailable', 'SMS gateway could not accept this message.', 503);
        }
    }
}
