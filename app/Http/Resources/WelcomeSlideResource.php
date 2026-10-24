<?php

namespace App\Http\Resources;

use App\Domain\Catalog\Models\WelcomeSlide;
use App\Http\Resources\Concerns\PublicAssets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WelcomeSlide */
class WelcomeSlideResource extends JsonResource
{
    use PublicAssets;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'image_url' => $this->assetUrl($this->image_path),
            'title' => ['ar' => $this->title_ar, 'en' => $this->title_en],
        ];
    }
}
