<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Seller;
use App\Http\Controllers\Controller;
use App\Http\Resources\SellerResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SellerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SellerResource::collection(Seller::query()->approved()->orderBy('id')->get());
    }

    public function show(string $slug): SellerResource
    {
        return new SellerResource(Seller::query()->approved()->where('slug', $slug)->firstOrFail());
    }
}
