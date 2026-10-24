<?php

namespace App\Http\Resources;

use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'locale' => $this->locale,
            'phone_verified' => $this->phone_verified_at !== null,
            'email_verified' => $this->email_verified_at !== null,
            'consents' => [
                'terms_version' => $this->latestConsent(\App\Domain\Identity\Enums\ConsentType::Terms)?->version,
                'privacy_version' => $this->latestConsent(\App\Domain\Identity\Enums\ConsentType::Privacy)?->version,
                'marketing' => $this->hasMarketingConsent(),
                'needs_update' => ! $this->hasAcceptedCurrentLegalVersions(),
            ],
        ];
    }
}
