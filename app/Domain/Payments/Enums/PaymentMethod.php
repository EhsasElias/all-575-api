<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

/** Only enabled, supported checkout methods are accepted. */
enum PaymentMethod: string
{
    case Mada = 'mada';
    case Card = 'card';
    case ApplePay = 'apple_pay';
    case CashOnDelivery = 'cash_on_delivery';
    /** Order fully covered by a promo code: nothing to charge. */
    case None = 'none';
}
