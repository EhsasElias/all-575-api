<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Contracts\OtpSender;
use App\Domain\Identity\Enums\OtpChannel;
use Illuminate\Support\Facades\Log;

/** Local and testing only: the code lands in the application log instead of an SMS. */
final class LogOtpSender implements OtpSender
{
    public function send(OtpChannel $channel, string $destination, string $code, string $locale): void
    {
        Log::info('OTP issued (log driver)', ['channel' => $channel->value, 'destination' => $destination, 'code' => $code]);
    }
}
