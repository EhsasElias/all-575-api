<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'request_key', 'kind', 'subject', 'description', 'status', 'reply'])]
class CustomerRequest extends Model
{
    use HasUlids;
}
