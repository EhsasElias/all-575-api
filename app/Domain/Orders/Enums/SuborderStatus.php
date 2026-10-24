<?php

declare(strict_types=1);

namespace App\Domain\Orders\Enums;

enum SuborderStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case AwaitingSeller = 'awaiting_seller';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case OnTheWay = 'on_the_way';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /** Before the seller starts preparing, a customer may still cancel for free. */
    public function customerMayCancel(): bool
    {
        return in_array($this, [self::AwaitingPayment, self::AwaitingSeller], true);
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::AwaitingPayment => [self::AwaitingSeller, self::Cancelled],
            self::AwaitingSeller => [self::Preparing, self::Cancelled],
            self::Preparing => [self::Ready, self::Cancelled],
            self::Ready => [self::OnTheWay, self::Cancelled],
            self::OnTheWay => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
