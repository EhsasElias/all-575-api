<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Seller;
use App\Http\Controllers\Controller;
use App\Http\Resources\SellerResource;

class SellerController extends Controller
{
    public function show(string $slug): SellerResource
    {
        return new SellerResource(Seller::query()->approved()->where('slug', $slug)->firstOrFail());
    }
}
