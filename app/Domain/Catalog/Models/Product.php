<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SellerStatus;
use App\Domain\Pricing\VatClass;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(ProductFactory::class)]
#[Fillable([
    'seller_id', 'category_id', 'slug', 'name_ar', 'name_en', 'description_ar', 'description_en', 'image_path',
    'price_halalas', 'vat_class', 'status', 'is_featured', 'rating_average', 'rating_count',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_halalas' => 'integer',
            'vat_class' => VatClass::class,
            'status' => ProductStatus::class,
            'is_featured' => 'boolean',
            'rating_average' => 'float',
        ];
    }

    protected function priceHalalas(): Attribute
    {
        return Attribute::make(set: static function (mixed $value): int {
            if ((! is_int($value) && (! is_string($value) || ! ctype_digit($value))) || (int) $value < 100 || (int) $value > 575) {
                throw new \InvalidArgumentException('Product price must be 100 to 575 integer halalas, VAT included.');
            }

            return (int) $value;
        });
    }

    /**
     * What a customer may see: published, in an active category, from an approved seller.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', ProductStatus::Published->value)
            ->whereHas('category', fn (Builder $q) => $q->where('is_active', true))
            ->whereHas('seller', fn (Builder $q) => $q->where('status', SellerStatus::Approved->value));
    }

    /** The VAT class as a typed value (the attribute itself is cast by Eloquent). */
    public function vatClass(): VatClass
    {
        return $this->vat_class;
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->where('is_active', true)->orderBy('sort_order');
    }
}
