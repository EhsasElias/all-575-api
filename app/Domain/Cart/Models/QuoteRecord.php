<?php

namespace App\Domain\Cart\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'address_id', 'promo_code', 'total_halalas', 'fingerprint', 'payload', 'expires_at'])]
class QuoteRecord extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'quotes';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'expires_at' => 'datetime'];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
