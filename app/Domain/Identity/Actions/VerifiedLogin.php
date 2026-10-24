<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;
use Laravel\Sanctum\NewAccessToken;

final readonly class VerifiedLogin
{
    public function __construct(
        public User $user,
        public NewAccessToken $token,
        public bool $isNewUser,
    ) {}
}
