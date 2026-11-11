<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared, account-scoped in-app inbox for marketplace roles.
 * Delivery failures must not roll back successful business decisions.
 */
final class RoleNotificationWriter
{
    public function send(int $userId, string $type, string $title, string $message, ?string $targetPath = null): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            DB::table('customer_notifications')->insert([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'target_path' => $targetPath,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Role notification could not be recorded', [
                'type' => $type,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
