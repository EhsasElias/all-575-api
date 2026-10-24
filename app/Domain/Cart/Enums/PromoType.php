<?php

declare(strict_types=1);

namespace App\Domain\Cart\Enums;

enum PromoType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
}
