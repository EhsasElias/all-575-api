<?php

declare(strict_types=1);

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/** Writes the audit trail for sensitive actions. Never put secrets or full card/OTP values in `$context`. */
final class AuditLogger
{
    /** @param array<string, mixed> $context */
    public function record(string $action, ?int $userId, ?Model $subject = null, array $context = [], ?string $ip = null): void
    {
        AuditLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'context' => $context === [] ? null : $context,
            'ip_address' => $ip ?? request()->ip(),
        ]);
    }
}
