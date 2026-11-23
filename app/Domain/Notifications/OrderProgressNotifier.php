<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\DB;

/** In-app progress notices are idempotent, branch-scoped and free of verification secrets. */
final class OrderProgressNotifier
{
    public function record(object $order, object $suborder, string $event, ?int $courierUserId = null): void
    {
        $messages = [
            'courier_picked_up' => ['تم استلام الشحنة', 'تم استلام الطلب %s من البائع وهو في الطريق.'],
            'courier_delivered' => ['تم تسليم الطلب', 'تم تسليم الطلب %s والتحقق من الاستلام.'],
            'courier_delivery_failed' => ['تعذر التسليم', 'تعذرت محاولة تسليم الطلب %s وتم تسجيل بلاغ للمتابعة.'],
        ];
        if (! isset($messages[$event])) {
            return;
        }

        $sellers = DB::table('seller_staff_access')
            ->where('seller_id', $suborder->seller_id)->where('is_active', true)
            ->whereIn('role', ['owner', 'branch_manager'])
            ->where(function ($query) use ($suborder): void {
                $query->whereNull('branch_id')->orWhere('branch_id', $suborder->branch_id);
            })->pluck('user_id')->all();
        $admins = DB::table('platform_admin_access')
            ->where('seller_id', $suborder->seller_id)
            ->where('is_active', true)->where('can_view_orders', true)
            ->pluck('user_id')->all();
        $owners = DB::table('platform_super_admin_grants')
            ->where('is_active', true)->pluck('user_id')->all();
        $users = array_unique(array_filter(array_merge(
            [(int) $order->user_id], $sellers, $admins, $owners, [$courierUserId]
        )));

        [$title, $template] = $messages[$event];
        foreach ($users as $userId) {
            DB::table('customer_notifications')->insertOrIgnore([
                'user_id' => $userId,
                'event_key' => $event.':'.$suborder->id,
                'type' => $event,
                'title' => $title,
                'message' => sprintf($template, $order->number),
                'target_path' => (int) $userId === (int) $order->user_id ? '/orders' : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
