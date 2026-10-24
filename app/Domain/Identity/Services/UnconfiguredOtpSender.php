<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\OtpSender;
use App\Domain\Identity\Enums\OtpChannel;
use App\Support\Exceptions\DomainRuleViolation;

/** The default until an SMS and email provider is chosen (roadmap decision D-03). */
final class UnconfiguredOtpSender implements OtpSender
{
    public function send(OtpChannel $channel, string $destination, string $code, string $locale): void
    {
        throw new DomainRuleViolation('otp_delivery_unavailable', 'Verification codes cannot be sent right now.', 503);
    }
}
