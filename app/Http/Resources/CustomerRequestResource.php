<?php

namespace App\Http\Resources;

use App\Domain\Identity\Models\CustomerRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerRequest */
class CustomerRequestResource extends JsonResource
{
    /** @return array{id: string, kind: string, subject: string, description: string, status: string, reply: ?string, created_at: string, updated_at: string} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'kind' => $this->kind, 'subject' => $this->subject,
            'description' => $this->description, 'status' => $this->status, 'reply' => $this->reply,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
