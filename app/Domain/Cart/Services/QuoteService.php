<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Addressing\Models\Address;
use App\Domain\Cart\Models\QuoteRecord;
use App\Domain\Identity\Models\User;

final class QuoteService
{
    public function __construct(private readonly QuoteBuilder $builder) {}

    /** Prices the cart and remembers the result for a few minutes so checkout can prove what the customer saw. */
    public function create(User $user, Address $address, ?string $promoCode): QuoteRecord
    {
        $quote = $this->builder->build($user, $address, $promoCode);

        return QuoteRecord::query()->create([
            'user_id' => $user->id,
            'address_id' => $address->id,
            'promo_code' => $quote->promoCode,
            'total_halalas' => $quote->total->halalas,
            'fingerprint' => $quote->fingerprint(),
            'payload' => $quote->toArray(),
            'expires_at' => now()->addMinutes((int) config('store.quote.ttl_minutes')),
        ]);
    }
}
