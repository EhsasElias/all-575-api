<?php

declare(strict_types=1);

namespace App\Domain\Identity\Contracts;

use App\Domain\Identity\Enums\OtpChannel;

interface OtpSender
{
    public function send(OtpChannel $channel, string $destination, string $code, string $locale): void;
}
