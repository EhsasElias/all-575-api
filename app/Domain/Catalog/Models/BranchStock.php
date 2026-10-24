<?php

namespace App\Domain\Catalog\Models;

use Database\Factories\BranchStockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(BranchStockFactory::class)]
#[Fillable(['branch_id', 'variant_id', 'on_hand', 'reserved'])]
class BranchStock extends Model
{
    /** @use HasFactory<BranchStockFactory> */
    use HasFactory;

    protected $table = 'branch_stock';

    public function available(): int
    {
        return max(0, $this->on_hand - $this->reserved);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
