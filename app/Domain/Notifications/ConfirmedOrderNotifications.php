<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Orders\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Notify only accounts permitted to see an order, once its confirmation transaction commits.
 * A single buyer order may contain multiple seller suborders.
 */
final class ConfirmedOrderNotifications
{
    public function __construct(private readonly RoleNotificationWriter $writer) {}

    public function send(Order $order): void
    {
        $order = $order->fresh();
        if ($order === null || ! in_array($order->status->value, ['confirmed', 'completed'], true)) {
            return;
        }

        $suborders = $order->suborders()->get(['seller_id', 'branch_id']);
        $sellerIds = $suborders->pluck('seller_id')->unique()->all();
        $branchIds = $suborders->pluck('branch_id')->unique()->all();
        $number = (string) $order->number;
        $eventKey = 'order_confirmed:'.$order->id;

        // The customer already receives an order-received notice at checkout; confirmation is separate.
        $this->writer->send(
            (int) $order->user_id,
            'order_confirmed',
            'تم تأكيد طلبك',
            'تم تأكيد الطلب '.$number.' ويمكنك متابعة حالته من صفحة طلباتي.',
            '/orders',
            $eventKey.':customer'
        );

        if ($sellerIds === []) {
            return;
        }

        // Include owners and assigned staff of the affected seller branches only.
        $sellerUsers = DB::table('seller_staff_access')
            ->join('users', 'users.id', '=', 'seller_staff_access.user_id')
            ->whereIn('seller_staff_access.seller_id', $sellerIds)
            ->where('seller_staff_access.is_active', true)
            ->where('users.is_active', true)
            ->where(function ($q) use ($branchIds): void {
                $q->whereNull('seller_staff_access.branch_id')
                    ->orWhereIn('seller_staff_access.branch_id', $branchIds);
            })
            ->distinct()->pluck('seller_staff_access.user_id');

        foreach ($sellerUsers as $userId) {
            $this->writer->send(
                (int) $userId,
                'seller_new_order',
                'طلب جديد للمتجر',
                'الطلب '.$number.' مؤكد ويحتاج إلى متابعة التجهيز.',
                '/account/seller-workspace?section=orders',
                $eventKey.':seller'
            );
        }

        // Operations administrators see only sellers for which they have order-view permission.
        $adminUsers = DB::table('platform_admin_access')
            ->join('users', 'users.id', '=', 'platform_admin_access.user_id')
            ->whereIn('platform_admin_access.seller_id', $sellerIds)
            ->where('platform_admin_access.is_active', true)
            ->where('platform_admin_access.can_view_orders', true)
            ->where('users.is_active', true)
            ->distinct()->pluck('platform_admin_access.user_id');

        foreach ($adminUsers as $userId) {
            $this->writer->send(
                (int) $userId,
                'admin_new_order',
                'طلب جديد للمراقبة',
                'تم تأكيد الطلب '.$number.' ضمن المتاجر التي تديرها.',
                '/account/admin#order-monitoring',
                $eventKey.':admin'
            );
        }

        $superUsers = DB::table('platform_super_admin_grants')
            ->join('users', 'users.id', '=', 'platform_super_admin_grants.user_id')
            ->where('platform_super_admin_grants.is_active', true)
            ->where('users.is_active', true)
            ->distinct()->pluck('platform_super_admin_grants.user_id');

        foreach ($superUsers as $userId) {
            $this->writer->send(
                (int) $userId,
                'super_admin_new_order',
                'طلب جديد في المنصة',
                'تم تأكيد الطلب '.$number.' في متجر ALL 5.75.',
                '/account/super-admin',
                $eventKey.':super_admin'
            );
        }
    }
}
