<?php

namespace App\Domain\Orders\Models;

use App\Domain\Catalog\Models\BranchStock;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'branch_stock_id', 'quantity', 'status', 'expires_at'])]
class StockReservation extends Model
{
    public const HELD = 'held';

    public const COMMITTED = 'committed';

    public const RELEASED = 'released';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<BranchStock, $this> */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(BranchStock::class, 'branch_stock_id');
    }
}
