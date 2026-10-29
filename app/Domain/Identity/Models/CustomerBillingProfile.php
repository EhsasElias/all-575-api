<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'buyer_type', 'company_name', 'registration_number', 'vat_number', 'billing_address'])]
class CustomerBillingProfile extends Model {}
