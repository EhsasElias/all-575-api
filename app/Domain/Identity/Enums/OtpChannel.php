<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum OtpChannel: string
{
    case Phone = 'phone';
    case Email = 'email';
}
