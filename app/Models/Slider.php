<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Slider extends Model
{ 
    protected $fillable = [
        'title_ar',
        'title_en',

        'description_ar',
        'description_en',

        'image',

        'button_text_ar',
        'button_text_en',

        'link',

        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}
