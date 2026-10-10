<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Seller extends Model
{
    protected $fillable = [
        'user_id',
        'name_ar',
        'name_en',
        'slug',
        'logo',
        'phone',
        'email',
        'description_ar',
        'description_en',
        'status',
        'commission_percentage',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'commission_percentage' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
