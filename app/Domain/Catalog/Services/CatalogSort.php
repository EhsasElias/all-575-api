<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;

class CatalogSort
{
    public static function apply(Builder $products, string $sort): void
    {
        if ($sort === 'best_selling') {
            $sales = OrderItem::query()->select('product_id')->selectRaw('SUM(quantity) AS units_sold')
                ->whereHas('order', fn (Builder $order) => $order->where('status', 'completed')->whereNotNull('paid_at'))
                ->groupBy('product_id');
            $products->leftJoinSub($sales, 'completed_sales', 'completed_sales.product_id', '=', 'products.id')
                ->select('products.*')->selectRaw('COALESCE(completed_sales.units_sold, 0) AS units_sold')
                ->orderByDesc('units_sold');
        }
        match ($sort) {
            'featured' => $products->orderByDesc('products.is_featured'),
            'rating' => $products->orderByDesc('products.rating_average'),
            'price_asc' => $products->orderBy('products.price_halalas'),
            'price_desc' => $products->orderByDesc('products.price_halalas'),
            default => null,
        };
        $products->orderByDesc('products.created_at')->orderBy('products.id');
    }
}
