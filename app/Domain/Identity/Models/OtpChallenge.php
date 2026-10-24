<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\OtpChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['channel', 'destination', 'code_hash', 'max_attempts', 'expires_at', 'ip_address'])]
class OtpChallenge extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => OtpChannel::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->invalidated_at === null
            && $this->expires_at->isFuture();
    }

    public function attemptsRemaining(): int
    {
        return max(0, $this->max_attempts - $this->attempts);
    }
}
