<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

enum SellerStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Suspended = 'suspended';
}
