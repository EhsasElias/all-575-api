<?php

namespace App\Http\Resources;

use App\Domain\Catalog\Models\Seller;
use App\Http\Resources\Concerns\PublicAssets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Seller */
class SellerResource extends JsonResource
{
    use PublicAssets;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'name' => ['ar' => $this->name_ar, 'en' => $this->name_en],
            'logo_url' => $this->assetUrl($this->logo_path),
        ];
    }
}
