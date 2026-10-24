<?php

namespace App\Domain\Reviews\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\Order;
use App\Domain\Reviews\Models\Review;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class SubmitReview
{
    /** Only a delivered purchase can be reviewed, once per product per order. */
    public function create(User $user, Order $order, string $productSlug, int $rating, ?string $body): Review
    {
        $product = Product::query()->where('slug', $productSlug)->firstOrFail();

        $delivered = $order->suborders()
            ->where('status', SuborderStatus::Delivered->value)
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->id))
            ->exists();

        if (! $delivered) {
            throw new DomainRuleViolation('review_not_allowed', 'You can review a product after it has been delivered.', 422);
        }

        try {
            $review = DB::transaction(function () use ($user, $order, $product, $rating, $body): Review {
                $review = Review::query()->create([
                    'user_id' => $user->id, 'order_id' => $order->id, 'product_id' => $product->id,
                    'rating' => $rating, 'body' => $body, 'status' => Review::PUBLISHED,
                    'editable_until' => now()->addDays((int) config('store.reviews.edit_window_days')),
                ]);
                $this->refreshProductRating($product);

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainRuleViolation('review_exists', 'You already reviewed this product for this order.', 409);
        }

        return $review;
    }

    public function update(Review $review, ?int $rating, ?string $body, bool $bodyProvided): Review
    {
        if (! $review->isEditable()) {
            throw new DomainRuleViolation('review_window_closed', 'This review can no longer be edited.', 409);
        }

        return DB::transaction(function () use ($review, $rating, $body, $bodyProvided): Review {
            $review->forceFill(array_filter([
                'rating' => $rating,
            ], fn (mixed $v): bool => $v !== null) + ($bodyProvided ? ['body' => $body] : []))->save();
            $this->refreshProductRating($review->product);

            return $review->refresh();
        });
    }

    private function refreshProductRating(Product $product): void
    {
        $published = Review::query()->where('product_id', $product->id)->where('status', Review::PUBLISHED);

        $product->forceFill([
            'rating_average' => round((float) ($published->avg('rating') ?? 0), 1),
            'rating_count' => $published->count(),
        ])->save();
    }
}
