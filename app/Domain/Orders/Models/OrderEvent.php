<?php

namespace App\Domain\Orders\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'suborder_id', 'type', 'actor', 'note', 'meta'])]
class OrderEvent extends Model
{
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
