<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Cart\Models\Cart;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Identity\Models\User;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class CartService
{
    /** Sets the quantity of a variant; zero removes the line. */
    public function setQuantity(User $user, int $variantId, int $quantity): void
    {
        $max = (int) config('store.cart.max_quantity_per_line');

        if ($quantity < 0 || $quantity > $max) {
            throw new DomainRuleViolation('quantity_out_of_range', "Quantity must be between 0 and {$max}.");
        }

        DB::transaction(function () use ($user, $variantId, $quantity): void {
            $cart = Cart::forUser($user);

            if ($quantity === 0) {
                $cart->items()->where('variant_id', $variantId)->delete();

                return;
            }

            $this->assertBuyable($variantId);

            $line = $cart->items()->where('variant_id', $variantId)->first();

            if ($line === null && $cart->items()->count() >= (int) config('store.cart.max_lines')) {
                throw new DomainRuleViolation('cart_full', 'The cart cannot hold more items.');
            }

            $cart->items()->updateOrCreate(['variant_id' => $variantId], ['quantity' => $quantity]);
        });
    }

    /**
     * Adds a guest's local cart to the signed-in cart. Items that are no longer buyable are skipped,
     * quantities are added and capped, and the call never fails because of one stale line.
     *
     * @param  list<array{variant_id: int, quantity: int}>  $items
     */
    public function merge(User $user, array $items): void
    {
        $max = (int) config('store.cart.max_quantity_per_line');

        DB::transaction(function () use ($user, $items, $max): void {
            $cart = Cart::forUser($user);

            foreach ($items as $item) {
                if (! $this->isBuyable($item['variant_id'])) {
                    continue;
                }

                $existing = $cart->items()->where('variant_id', $item['variant_id'])->value('quantity') ?? 0;
                $quantity = min($max, $existing + max(1, $item['quantity']));

                if ($existing === 0 && $cart->items()->count() >= (int) config('store.cart.max_lines')) {
                    continue;
                }

                $cart->items()->updateOrCreate(['variant_id' => $item['variant_id']], ['quantity' => $quantity]);
            }
        });
    }

    public function clear(User $user): void
    {
        Cart::forUser($user)->items()->delete();
    }

    private function assertBuyable(int $variantId): void
    {
        if (! $this->isBuyable($variantId)) {
            throw new DomainRuleViolation('variant_unavailable', 'This product is not available.', 404);
        }
    }

    private function isBuyable(int $variantId): bool
    {
        return ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn (Builder $query) => $query->visible())
            ->whereKey($variantId)
            ->exists();
    }
}
