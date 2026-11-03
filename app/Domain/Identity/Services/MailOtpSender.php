<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\OtpSender;
use App\Domain\Identity\Enums\OtpChannel;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Support\Facades\Mail;

/**
 * SMTP email delivery for the existing database-backed OTP system.
 * Phone OTP needs a separately contracted SMS gateway; never fake delivery.
 */
final class MailOtpSender implements OtpSender
{
    public function send(OtpChannel $channel, string $destination, string $code, string $locale): void
    {
        if ($channel !== OtpChannel::Email) {
            throw new DomainRuleViolation('otp_delivery_unavailable', 'SMS gateway is not configured.', 503);
        }
        $subject = $locale === 'ar' ? 'رمز التحقق لتطبيق ALL 5.75' : 'ALL 5.75 verification code';
        $body = $locale === 'ar'
            ? "رمز التحقق الخاص بك: {$code}\nتنتهي صلاحية الرمز بعد 5 دقائق. لا تشارك الرمز مع أحد."
            : "Your verification code is: {$code}\nIt expires in 5 minutes. Do not share this code.";
        Mail::raw($body, static function ($message) use ($destination, $subject): void {
            $message->to($destination)->subject($subject);
        });
    }
}
