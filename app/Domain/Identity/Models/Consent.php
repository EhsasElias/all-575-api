<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\ConsentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'type', 'version', 'granted', 'locale', 'ip_address'])]
class Consent extends Model
{
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ConsentType::class,
            'granted' => 'boolean',
        ];
    }
}
