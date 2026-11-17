<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Day 13: role-scoped, non-sensitive delivery milestone notifications.
 * Called only after the transaction commits; notification failures never undo an order.
 */
final class CourierMilestoneNotifications
{
    public function __construct(private readonly RoleNotificationWriter $writer) {}

    public function send(string $suborderId, string $type): void
    {
        try {
            $row = DB::table('suborders as s')->join('orders as o', 'o.id', '=', 's.order_id')
                ->where('s.id', $suborderId)
                ->first(['s.id', 's.seller_id', 's.branch_id', 's.order_id',
                    'o.user_id as buyer_id', 'o.number as order_number']);
            if (!$row) {
                return;
            }
            $titles = [
                'picked_up' => 'تم استلام طلبك من المتجر',
                'delivered' => 'تم توصيل طلبك',
                'delivery_failed' => 'تحديث بشأن توصيل طلبك',
            ];
            if (!isset($titles[$type])) {
                return;
            }
            $eventKey = 'courier_'.$type.':'.$suborderId;
            $number = (string) $row->order_number;
            $this->writer->send((int) $row->buyer_id, 'courier_'.$type,
                $titles[$type], 'تم تحديث حالة الطلب '.$number.'. راجع تفاصيل الطلب.',
                '/orders', $eventKey.':buyer');

            $staff = DB::table('seller_staff_access as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.seller_id', $row->seller_id)
                ->where('a.is_active', true)->where('u.is_active', true)
                ->where(function ($q) use ($row): void {
                    $q->whereNull('a.branch_id')->orWhere('a.branch_id', $row->branch_id);
                })->distinct()->pluck('a.user_id');
            foreach ($staff as $userId) {
                $this->writer->send((int) $userId, 'seller_courier_'.$type,
                    'تحديث توصيل الطلب', 'تم تحديث حالة توصيل الطلب '.$number.'.',
                    '/account/seller-workspace?section=orders', $eventKey.':seller');
            }

            $admins = DB::table('platform_admin_access as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('a.seller_id', $row->seller_id)
                ->where('a.is_active', true)->where('a.can_view_orders', true)
                ->where('u.is_active', true)->distinct()->pluck('a.user_id');
            foreach ($admins as $userId) {
                $this->writer->send((int) $userId, 'admin_courier_'.$type,
                    'تحديث عملية التوصيل', 'تم تحديث حالة توصيل الطلب '.$number.'.',
                    '/account/admin#order-monitoring', $eventKey.':admin');
            }

            $courierId = DB::table('courier_assignments as a')
                ->join('courier_profiles as c', 'c.id', '=', 'a.courier_profile_id')
                ->where('a.suborder_id', $suborderId)->value('c.user_id');
            if ($courierId) {
                $this->writer->send((int) $courierId, 'courier_'.$type,
                    'تحديث حالة المهمة', 'تم تحديث حالة مهمة الطلب '.$number.'.',
                    '/account/courier', $eventKey.':courier');
            }
        } catch (Throwable $e) {
            Log::warning('Courier milestone notification failed', [
                'suborder_id' => $suborderId, 'type' => $type,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
