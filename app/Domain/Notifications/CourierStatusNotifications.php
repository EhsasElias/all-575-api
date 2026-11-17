<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\DB;

/**
 * Day 13: notify only users who may see the affected seller suborder.
 * No customer address, phone, handover code or payment details enter notifications.
 * Call after the order transition commits.
 */
final class CourierStatusNotifications
{
    public function __construct(private readonly RoleNotificationWriter $writer) {}

    public function send(string $suborderId, string $event): void
    {
        $context = DB::table('suborders as s')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('s.id', $suborderId)
            ->first(['s.id', 's.seller_id', 's.branch_id', 's.status', 'o.number', 'o.user_id']);
        if ($context === null) {
            return;
        }

        $messages = [
            'assigned' => ['تم إسناد مندوب', 'تم إرسال مهمة توصيل للطلب'],
            'picked_up' => ['الطلب في الطريق', 'استلم المندوب الطلب وأصبح في الطريق'],
            'delivered' => ['تم تسليم الطلب', 'تم التحقق من تسليم الطلب'],
            'delivery_failed' => ['تأخر تسليم الطلب', 'تم تسجيل محاولة توصيل غير ناجحة للطلب'],
        ];
        if (!array_key_exists($event, $messages)) {
            return;
        }

        [$title, $prefix] = $messages[$event];
        $body = $prefix.' '.$context->number.'.';
        $key = 'courier:'.$event.':'.$context->id;

        $this->writer->send((int) $context->user_id, 'courier_'.$event,
            $title, $body, '/orders/'.$context->id, $key.':customer');

        $staff = DB::table('seller_staff_access')
            ->join('users', 'users.id', '=', 'seller_staff_access.user_id')
            ->where('seller_staff_access.seller_id', $context->seller_id)
            ->where('seller_staff_access.is_active', true)
            ->where('users.is_active', true)
            ->where(function ($query) use ($context): void {
                $query->whereNull('seller_staff_access.branch_id')
                    ->orWhere('seller_staff_access.branch_id', $context->branch_id);
            })->distinct()->pluck('seller_staff_access.user_id');

        foreach ($staff as $userId) {
            $this->writer->send((int) $userId, 'seller_courier_'.$event,
                $title, $body, '/account/seller-workspace?section=orders',
                $key.':seller:'.$userId);
        }

        $admins = DB::table('platform_admin_access')
            ->join('users', 'users.id', '=', 'platform_admin_access.user_id')
            ->where('platform_admin_access.seller_id', $context->seller_id)
            ->where('platform_admin_access.is_active', true)
            ->where('platform_admin_access.can_view_orders', true)
            ->where('users.is_active', true)
            ->distinct()->pluck('platform_admin_access.user_id');

        foreach ($admins as $userId) {
            $this->writer->send((int) $userId, 'admin_courier_'.$event,
                $title, $body, '/account/admin#courier-operations',
                $key.':admin:'.$userId);
        }

        $courier = DB::table('courier_assignments as a')
            ->join('courier_profiles as c', 'c.id', '=', 'a.courier_profile_id')
            ->where('a.suborder_id', $suborderId)
            ->value('c.user_id');

        if ($courier !== null) {
            $this->writer->send((int) $courier, 'courier_'.$event,
                $title, $body, '/account/courier', $key.':courier');
        }
    }
}
