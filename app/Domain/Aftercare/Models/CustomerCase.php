<?php

namespace App\Domain\Aftercare\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'order_id', 'kind', 'status', 'order_item_id', 'quantity', 'subject', 'request_key', 'request_hash'])]
class CustomerCase extends Model
{
    use HasUlids;

    /** @return HasMany<CustomerCaseMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(CustomerCaseMessage::class)->orderBy('id');
    }
}
