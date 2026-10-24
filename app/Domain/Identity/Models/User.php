<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\ConsentType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

#[UseFactory(UserFactory::class)]
#[Fillable(['name', 'phone', 'email', 'phone_verified_at', 'email_verified_at', 'locale', 'is_active', 'last_login_at'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Consent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function isVerified(): bool
    {
        return $this->phone_verified_at !== null || $this->email_verified_at !== null;
    }

    public function latestConsent(ConsentType $type): ?Consent
    {
        return $this->consents()->where('type', $type->value)->latest('id')->first();
    }

    public function hasAcceptedCurrentLegalVersions(): bool
    {
        return $this->latestConsent(ConsentType::Terms)?->version === config('store.terms_version')
            && $this->latestConsent(ConsentType::Privacy)?->version === config('store.privacy_version');
    }

    public function hasMarketingConsent(): bool
    {
        return $this->latestConsent(ConsentType::Marketing)?->granted === true;
    }
}
