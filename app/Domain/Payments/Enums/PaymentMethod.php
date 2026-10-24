<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/** Cash on delivery, SADAD, BNPL and bank transfer need separate approval (roadmap D-06) and are not listed. */
enum PaymentMethod: string
{
    case Mada = 'mada';
    case Card = 'card';
    case ApplePay = 'apple_pay';
    /** Order fully covered by a promo code: nothing to charge. */
    case None = 'none';
}
