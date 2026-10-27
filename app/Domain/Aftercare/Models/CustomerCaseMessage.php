<?php

namespace App\Domain\Aftercare\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['author', 'body'])]
class CustomerCaseMessage extends Model {}
