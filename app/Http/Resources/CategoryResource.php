<?php

namespace App\Http\Resources;

use App\Domain\Catalog\Models\Category;
use App\Http\Resources\Concerns\PublicAssets;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
class CategoryResource extends JsonResource
{
    use PublicAssets;

    /**
     * @return array{id: int, slug: string, name: array{ar: string, en: string}, image_url: ?string, children?: list<array{id: int, slug: string, name: array{ar: string, en: string}, image_url: ?string}>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => ['ar' => $this->name_ar, 'en' => $this->name_en],
            'image_url' => $this->assetUrl($this->image_path),
            'children' => $this->whenLoaded('children', fn () => self::collection($this->children)),
        ];
    }
}
