<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
