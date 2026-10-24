<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['suborder_id', 'code_encrypted', 'attempts', 'max_attempts', 'expires_at', 'verified_at'])]
class DeliveryCode extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'verified_at' => 'datetime', 'code_encrypted' => 'encrypted'];
    }

    /** @return BelongsTo<Suborder, $this> */
    public function suborder(): BelongsTo
    {
        return $this->belongsTo(Suborder::class);
    }
}
