<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\SellerStatus;
use Database\Factories\SellerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(SellerFactory::class)]
#[Fillable(['slug', 'name_ar', 'name_en', 'logo_path', 'status'])]
class Seller extends Model
{
    /** @use HasFactory<SellerFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => SellerStatus::class];
    }

    /** @param  Builder<static>  $query */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', SellerStatus::Approved->value);
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
