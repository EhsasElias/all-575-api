<?php

namespace App\Http\Resources;

use App\Domain\Reviews\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expects `user` loaded for the public list.
 *
 * @mixin Review
 */
class ReviewResource extends JsonResource
{
    /**
     * @return array{id: int, rating: int, body: ?string, reviewer: ?string, created_at: string, editable_until?: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'body' => $this->body,
            'reviewer' => $this->reviewerDisplayName(),
            'created_at' => $this->created_at,
            'editable_until' => $this->when($request->user()?->id === $this->user_id, fn () => $this->editable_until),
        ];
    }
}
