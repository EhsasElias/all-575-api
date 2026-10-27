<?php

namespace App\Http\Resources;

use App\Domain\Aftercare\Models\CustomerCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CustomerCase */
class CustomerCaseResource extends JsonResource
{
    /** @return array{id: string, order_id: string, kind: 'support'|'return', status: 'submitted'|'in_review'|'resolved'|'rejected', subject: string, order_item_id: ?int, quantity: ?int, created_at: string, updated_at: string, messages: list<array{id: int, author: 'customer'|'support', body: string, created_at: string}>} */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'order_id' => $this->order_id, 'kind' => $this->kind,
            'status' => $this->status, 'subject' => $this->subject,
            'order_item_id' => $this->order_item_id, 'quantity' => $this->quantity,
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
            'messages' => $this->messages->map(fn ($message): array => [
                'id' => $message->id, 'author' => $message->author, 'body' => $message->body, 'created_at' => $message->created_at,
            ])->all(),
        ];
    }
}
