<?php

declare(strict_types=1);

namespace App\Domain\Orders\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Payments\Enums\PaymentMethod;

final readonly class PlaceOrderCommand
{
    public function __construct(
        public User $user,
        public string $quoteId,
        public int $slotId,
        public PaymentMethod $method,
        public string $idempotencyKey,
        public ?string $notes,
    ) {}
}
