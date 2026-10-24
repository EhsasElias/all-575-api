<?php

namespace App\Domain\Reviews\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'order_id', 'product_id', 'rating', 'body', 'status', 'editable_until'])]
class Review extends Model
{
    public const PUBLISHED = 'published';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['editable_until' => 'datetime', 'rating' => 'integer'];
    }

    public function isEditable(): bool
    {
        return $this->editable_until->isFuture();
    }

    /** "Sara Alharbi" becomes "Sara A." so a review never shows a full name. */
    public function reviewerDisplayName(): ?string
    {
        $parts = preg_split('/\s+/u', trim((string) $this->user->name), -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return null;
        }

        return count($parts) === 1 ? $parts[0] : $parts[0].' '.mb_substr($parts[1], 0, 1).'.';
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
