<?php

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records in-app notifications without interrupting the business transaction.
 * A dedicated delivery channel can be introduced later without changing callers.
 */
final class CustomerNotificationWriter
{
    public function orderPlaced(int $userId, string $orderId): void
    {
        try {
            DB::table('customer_notifications')->insertOrIgnore([
                'user_id' => $userId,
                'source_order_id' => $orderId,
                'type' => 'order_placed',
                'title' => 'تم استلام طلبك',
                'message' => 'تم تسجيل طلبك بنجاح. يمكنك متابعة حالته من صفحة طلباتي.',
                'target_path' => '/orders',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Customer notification could not be recorded', [
                'order_id' => $orderId,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
