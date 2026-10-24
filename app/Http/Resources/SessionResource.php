<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/** @mixin PersonalAccessToken */
class SessionResource extends JsonResource
{
    /**
     * @return array{id: int, device_name: string, created_at: string, last_used_at: ?string, expires_at: ?string, is_current: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_name' => $this->name,
            'created_at' => $this->created_at,
            'last_used_at' => $this->last_used_at,
            'expires_at' => $this->expires_at,
            'is_current' => $this->id === $request->user()?->currentAccessToken()?->id,
        ];
    }
}
