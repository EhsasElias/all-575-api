<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class DeliveryVerificationController extends Controller
{
    /**
     * The buyer requests a separate delivery secret after the parcel is picked up.
     * Never expose the secret in courier job lists or tracking responses.
     */
    public function issue(Request $request, string $suborder): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $suborder): array {
            // Match the same lock order as courier verification: assignment, then suborder.
            $assignment = DB::table('courier_assignments')
                ->where('suborder_id', $suborder)->lockForUpdate()->first();
            abort_unless($assignment, 404);

            $row = DB::table('suborders as s')->join('orders as o', 'o.id', '=', 's.order_id')
                ->where('s.id', $suborder)->where('o.user_id', $request->user()->id)
                ->select('s.status', 'o.paid_at', 'o.status as order_status')->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'on_the_way' && $row->paid_at !== null
                && $row->order_status === 'confirmed' && $assignment->status === 'picked_up',
                409, 'Delivery verification is available only after pickup.');

            $openIncident = DB::table('delivery_incidents')->where('suborder_id', $suborder)
                ->where('type', 'delivery_issue')->where('status', '!=', 'resolved')->exists();
            abort_if($openIncident, 409, 'Resolve the delivery incident before verification.');

            // Reissuing within two minutes is blocked; the old proof is invalidated on reissue.
            abort_if($assignment->delivery_code_expires_at !== null
                && Carbon::parse($assignment->delivery_code_expires_at)->subMinutes(8)->isFuture(),
                429, 'Please wait before requesting another verification code.');

            $code = (string) random_int(100000, 999999);
            DB::table('courier_assignments')->where('id', $assignment->id)->update([
                'delivery_code_hash' => Hash::make($code),
                'delivery_code_expires_at' => now()->addMinutes(10),
                'delivery_attempts' => 0,
                'updated_at' => now(),
            ]);
            DB::table('courier_assignment_events')->insert([
                'assignment_id' => $assignment->id,
                'actor_user_id' => $request->user()->id,
                'courier_profile_id' => $assignment->courier_profile_id,
                'action' => 'delivery_code_issued',
                'reason' => null, 'created_at' => now(),
            ]);

            return ['delivery_code' => $code, 'expires_in_seconds' => 600];
        }, 3);

        return response()->json(['data' => $result], 201)
            ->header('Cache-Control', 'no-store, private');
    }

    /** Verify proof under lock before making all delivery state changes. */
    public function deliver(Request $request, int $assignment): JsonResponse
    {
        $validated = $request->validate(['delivery_code' => ['required', 'digits:6']]);
        abort_unless($request->user()->is_active, 403);

        $result = DB::transaction(function () use ($request, $assignment, $validated): string {
            $job = DB::table('courier_assignments')
                ->where('id', $assignment)->lockForUpdate()->first();
            abort_unless($job, 404);
            $courier = DB::table('courier_profiles')->where('id', $job->courier_profile_id)
                ->where('user_id', $request->user()->id)
                ->where('approval_status', 'approved')->first();
            abort_unless($courier, 404);
            abort_unless($job->status === 'picked_up', 409, 'This job is not ready for delivery.');

            $suborder = DB::table('suborders')->where('id', $job->suborder_id)->lockForUpdate()->first();
            abort_unless($suborder && $suborder->status === 'on_the_way', 409);

            $order = DB::table('orders')->where('id', $suborder->order_id)->lockForUpdate()->first();
            abort_unless($order && $order->paid_at !== null && $order->status === 'confirmed', 409);

            $unresolved = DB::table('delivery_incidents')
                ->where('suborder_id', $suborder->id)->where('type', 'delivery_issue')
                ->where('status', '!=', 'resolved')->exists();
            abort_if($unresolved, 409, 'Unresolved delivery incident blocks completion.');

            abort_unless($job->delivery_code_hash
                && $job->delivery_code_expires_at
                && Carbon::parse($job->delivery_code_expires_at)->isFuture()
                && (int) $job->delivery_attempts < 5,
                409, 'Delivery proof expired or locked.');

            if (! Hash::check($validated['delivery_code'], $job->delivery_code_hash)) {
                DB::table('courier_assignments')->where('id', $job->id)->update([
                    'delivery_attempts' => (int) $job->delivery_attempts + 1,
                    'updated_at' => now(),
                ]);

                return 'invalid_code';
            }

            DB::table('courier_assignments')->where('id', $job->id)->update([
                'status' => 'delivered', 'delivered_at' => now(),
                'delivery_code_hash' => null, 'delivery_code_expires_at' => null,
                'updated_at' => now(),
            ]);
            DB::table('suborders')->where('id', $suborder->id)->update([
                'status' => 'delivered', 'delivered_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('courier_assignment_events')->insert([
                'assignment_id' => $job->id, 'actor_user_id' => $request->user()->id,
                'courier_profile_id' => $courier->id,
                'action' => 'delivered', 'reason' => null, 'created_at' => now(),
            ]);
            DB::table('order_events')->insert([
                'order_id' => $order->id, 'suborder_id' => $suborder->id,
                'type' => 'courier_delivered', 'actor' => 'courier',
                'note' => 'Customer delivery verification succeeded',
                'meta' => null, 'created_at' => now(),
            ]);

            // Only complete a paid order after every seller parcel is terminal.
            $open = DB::table('suborders')->where('order_id', $order->id)
                ->whereNotIn('status', ['delivered', 'cancelled'])->exists();
            if (! $open) {
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => 'completed', 'updated_at' => now(),
                ]);
            }

            // Never include the one-time proof or recipient address in notifications.
            // Each role only receives notifications for sellers within its active scope.
            $sellerUserIds = DB::table('seller_staff_access')
                ->where('seller_id', $suborder->seller_id)->where('is_active', true)
                ->whereIn('role', ['owner', 'branch_manager'])
                ->where(function ($q) use ($suborder): void {
                    $q->whereNull('branch_id')->orWhere('branch_id', $suborder->branch_id);
                })->pluck('user_id')->all();
            $adminUserIds = DB::table('platform_admin_access')
                ->where('seller_id', $suborder->seller_id)->where('is_active', true)
                ->where('can_view_orders', true)->pluck('user_id')->all();
            $recipients = array_unique(array_merge([(int) $order->user_id], $sellerUserIds, $adminUserIds));
            foreach ($recipients as $userId) {
                DB::table('customer_notifications')->insertOrIgnore([
                    'user_id' => $userId,
                    'event_key' => 'courier_delivered:'.$suborder->id,
                    'type' => 'order_delivered',
                    'title' => 'تم تسليم الطلب',
                    'message' => 'تم تسليم الطلب '.$order->number.' والتحقق من الاستلام.',
                    'target_path' => '/orders',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return 'delivered';
        }, 3);

        abort_if($result === 'invalid_code', 422, 'Incorrect delivery verification code.');

        return response()->json(['data' => ['status' => $result]]);
    }
}
