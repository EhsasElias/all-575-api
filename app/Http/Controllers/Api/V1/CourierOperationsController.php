<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\OrderProgressNotifier;
use App\Domain\Notifications\RoleNotificationWriter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

final class CourierOperationsController extends Controller
{
    private function permittedSellers(Request $request): array
    {
        abort_unless($request->user()->is_active, 403);

        return DB::table('platform_admin_access')->where('user_id', $request->user()->id)
            ->where('is_active', true)->where('can_view_orders', true)->pluck('seller_id')->all();
    }

    private function scopedOrder(Request $request, string $suborderId, bool $lock = false): object
    {
        $ids = $this->permittedSellers($request);
        abort_if($ids === [], 403);
        $q = DB::table('suborders')->join('orders', 'orders.id', '=', 'suborders.order_id')
            ->where('suborders.id', $suborderId)->whereIn('suborders.seller_id', $ids)
            ->select('suborders.*', 'orders.service_zone_id', 'orders.paid_at', 'orders.status as order_status');
        $row = ($lock ? $q->lockForUpdate() : $q)->first();
        abort_unless($row, 404);

        return $row;
    }

    private function courier(Request $request): object
    {
        abort_unless($request->user()->is_active, 403);
        $courier = DB::table('courier_profiles')
            ->where('user_id', $request->user()->id)->first();
        abort_unless($courier !== null, 403);

        return $courier;
    }

    private function record(int $id, ?int $actor, ?int $courierId, string $action, ?string $reason = null): void
    {
        DB::table('courier_assignment_events')->insert([
            'assignment_id' => $id, 'actor_user_id' => $actor,
            'courier_profile_id' => $courierId, 'action' => $action,
            'reason' => $reason, 'created_at' => now(),
        ]);
    }

    public function couriers(Request $request): JsonResponse
    {
        $isOwner = DB::table('platform_super_admin_grants')
            ->where('user_id', $request->user()->id)->where('is_active', true)->exists();
        abort_if(! $isOwner && $this->permittedSellers($request) === [], 403);

        return response()->json(['data' => DB::table('courier_profiles as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->select('c.id', 'c.user_id', 'u.name', 'c.service_zone_id',
                'c.vehicle_type', 'c.approval_status', 'c.is_online')
            ->orderBy('c.id')->limit(200)->get()]);
    }

    public function register(Request $request): JsonResponse
    {
        abort_if($this->permittedSellers($request) === [], 403);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'service_zone_id' => ['required', 'integer', 'exists:service_zones,id'],
            'vehicle_type' => ['required', 'string', Rule::in(['motorcycle', 'car', 'van', 'bicycle'])],
        ]);
        // Admin can propose an existing verified user, but cannot approve them.
        $user = DB::table('users')->where('id', $data['user_id'])->where('is_active', true)
            ->where(function ($q): void {
                $q->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at');
            })->first();
        abort_unless($user, 422, 'Courier must have a verified, active user account.');
        abort_if(DB::table('courier_profiles')->where('user_id', $data['user_id'])->exists(), 409);
        $id = DB::table('courier_profiles')->insertGetId($data + [
            'approval_status' => 'pending', 'is_online' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $id, 'approval_status' => 'pending']], 201);
    }

    public function approve(Request $request, int $courier): JsonResponse
    {
        $isOwner = DB::table('platform_super_admin_grants')
            ->where('user_id', $request->user()->id)->where('is_active', true)->exists();
        abort_unless($request->user()->is_active && $isOwner, 403);
        $data = $request->validate(['approved' => ['required', 'boolean']]);
        $changed = DB::table('courier_profiles')->where('id', $courier)->update([
            'approval_status' => $data['approved'] ? 'approved' : 'rejected',
            'is_online' => false, 'updated_at' => now(),
        ]);
        abort_unless($changed, 404);

        $courierUserId = DB::table('courier_profiles')->where('id', $courier)->value('user_id');
        if ($courierUserId !== null) {
            $approved = $data['approved'];
            app(RoleNotificationWriter::class)->send(
                (int) $courierUserId,
                $approved ? 'courier_approved' : 'courier_rejected',
                $approved ? 'تم اعتماد حساب المندوب' : 'لم يتم اعتماد حساب المندوب',
                $approved
                    ? 'تم اعتماد حسابك كمندوب توصيل. يمكنك الآن ضبط حالة التوفر واستقبال المهام.'
                    : 'لم يتم اعتماد ملف المندوب الخاص بك. يمكنك مراجعة الإدارة.',
                '/account/courier',
                'courier_approval:'.$courier.':'.($approved ? 'approved' : 'rejected'),
            );
        }

        return response()->json(['data' => ['approval_status' => $data['approved'] ? 'approved' : 'rejected']]);
    }

    public function profile(Request $request): JsonResponse
    {
        $courier = $this->courier($request);

        return response()->json(['data' => [
            'id' => $courier->id, 'service_zone_id' => $courier->service_zone_id,
            'approval_status' => $courier->approval_status,
            'vehicle_type' => $courier->vehicle_type, 'is_online' => (bool) $courier->is_online,
        ]]);
    }

    public function availability(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        abort_unless($courier->approval_status === 'approved', 403);
        $data = $request->validate(['is_online' => ['required', 'boolean']]);
        DB::table('courier_profiles')->where('id', $courier->id)
            ->update(['is_online' => $data['is_online'], 'updated_at' => now()]);

        return response()->json(['data' => ['is_online' => $data['is_online']]]);
    }

    public function assign(Request $request, string $suborder): JsonResponse
    {
        abort_if($this->permittedSellers($request) === [], 403);
        $input = $request->validate([
            'courier_profile_id' => ['required', 'integer', 'exists:courier_profiles,id'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $result = DB::transaction(function () use ($request, $suborder, $input): array {
            $order = $this->scopedOrder($request, $suborder, true);
            abort_unless($order->status === 'ready' && $order->ready_at !== null
                && $order->paid_at !== null && in_array($order->order_status, ['confirmed', 'completed'], true),
                409, 'Only paid, seller-ready orders can be assigned.');
            $courier = DB::table('courier_profiles')->where('id', $input['courier_profile_id'])->first();
            abort_unless($courier && $courier->approval_status === 'approved'
                && (bool) $courier->is_online && (int) $courier->service_zone_id === (int) $order->service_zone_id,
                422, 'Courier must be approved, online, and in the order service zone.');
            $existing = DB::table('courier_assignments')->where('suborder_id', $suborder)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(in_array($existing->status, ['declined', 'expired', 'offered'], true), 409,
                    'Accepted or picked-up assignments cannot be replaced.');
                abort_if($existing->status === 'offered' && Carbon::parse($existing->expires_at)->isFuture()
                    && (int) $existing->courier_profile_id === (int) $courier->id, 409,
                    'This courier already has a pending offer.');
                abort_if($existing->status === 'offered' && empty(trim((string) ($input['reason'] ?? ''))), 422,
                    'Reason is required to reassign an active offer.');
            }
            $attributes = [
                'courier_profile_id' => $courier->id, 'status' => 'offered',
                'expires_at' => now()->addMinutes(15), 'accepted_at' => null,
                'pickup_code_hash' => null, 'pickup_code_encrypted' => null,
                'pickup_code_expires_at' => null, 'pickup_attempts' => 0,
                'parcel_count' => null, 'picked_up_at' => null,
                'delivery_code_hash' => null, 'delivery_code_expires_at' => null,
                'delivery_attempts' => 0, 'delivered_at' => null,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('courier_assignments')->where('id', $existing->id)->update($attributes);
                $id = $existing->id;
            } else {
                $id = DB::table('courier_assignments')->insertGetId($attributes + [
                    'suborder_id' => $suborder, 'created_at' => now(),
                ]);
            }
            $this->record($id, $request->user()->id, $courier->id,
                $existing ? 'reassigned' : 'assigned', $input['reason'] ?? null);

            return ['assignment_id' => $id, 'status' => 'offered'];
        }, 3);

        $recipientUserId = DB::table('courier_profiles')
            ->where('id', $input['courier_profile_id'])->value('user_id');
        if ($recipientUserId !== null) {
            $assignmentUpdatedAt = DB::table('courier_assignments')
                ->where('id', $result['assignment_id'])->value('updated_at');
            app(RoleNotificationWriter::class)->send(
                (int) $recipientUserId,
                'courier_offer',
                'عرض توصيل جديد',
                'لديك عرض توصيل للمهمة رقم '.$result['assignment_id'].'؛ يرجى الرد قبل انتهاء المهلة.',
                '/account/courier',
                'courier_offer:'.$result['assignment_id'].':'.strtotime((string) $assignmentUpdatedAt),
            );
        }

        return response()->json(['data' => $result]);
    }

    public function jobs(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        abort_unless($courier->approval_status === 'approved', 403);
        $jobs = DB::table('courier_assignments as a')
            ->join('suborders as s', 's.id', '=', 'a.suborder_id')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->join('branches as b', 'b.id', '=', 's.branch_id')
            ->where('a.courier_profile_id', $courier->id)
            ->select('a.id', 'a.suborder_id', 'a.status', 'a.expires_at',
                'a.accepted_at', 'a.picked_up_at', 'a.delivered_at', 'a.parcel_count',
                'o.number as order_number', 's.branch_id', 'b.lat as pickup_lat',
                'b.lng as pickup_lng', 's.status as suborder_status')
            ->orderByDesc('a.id')->limit(100)->get();

        return response()->json(['data' => $jobs]);
    }

    public function respond(Request $request, int $assignment): JsonResponse
    {
        $courier = $this->courier($request);
        $data = $request->validate(['decision' => ['required', Rule::in(['accept', 'decline'])]]);
        abort_unless($courier->approval_status === 'approved', 403);
        $status = DB::transaction(function () use ($request, $courier, $assignment, $data): string {
            $job = DB::table('courier_assignments')->where('id', $assignment)->lockForUpdate()->first();
            abort_unless($job && (int) $job->courier_profile_id === (int) $courier->id, 404);
            abort_unless($job->status === 'offered', 409, 'Offer already answered.');
            if (Carbon::parse($job->expires_at)->isPast()) {
                DB::table('courier_assignments')->where('id', $job->id)
                    ->update(['status' => 'expired', 'updated_at' => now()]);
                $this->record($job->id, $request->user()->id, $courier->id, 'expired');

                return 'expired';
            }
            if ($data['decision'] === 'accept') {
                abort_unless((bool) $courier->is_online, 409, 'Go online to accept an offer.');
            }
            $status = $data['decision'] === 'accept' ? 'accepted' : 'declined';
            DB::table('courier_assignments')->where('id', $job->id)->update([
                'status' => $status, 'accepted_at' => $status === 'accepted' ? now() : null,
                'updated_at' => now(),
            ]);
            $this->record($job->id, $request->user()->id, $courier->id, $status);

            return $status;
        }, 3);

        return response()->json(['data' => ['status' => $status]], $status === 'expired' ? 409 : 200);
    }

    public function pickupCode(Request $request, string $suborder): JsonResponse
    {
        $order = $this->scopedOrder($request, $suborder);
        abort_unless($order->status === 'ready', 409);
        $code = DB::transaction(function () use ($request, $suborder): string {
            $assignment = DB::table('courier_assignments')->where('suborder_id', $suborder)->lockForUpdate()->first();
            abort_unless($assignment && $assignment->status === 'accepted', 409, 'No accepted courier.');
            if ($assignment->pickup_code_encrypted && Carbon::parse($assignment->pickup_code_expires_at)->isFuture()) {
                return Crypt::decryptString($assignment->pickup_code_encrypted);
            }
            $code = (string) random_int(100000, 999999);
            DB::table('courier_assignments')->where('id', $assignment->id)->update([
                'pickup_code_hash' => Hash::make($code),
                'pickup_code_encrypted' => Crypt::encryptString($code),
                'pickup_code_expires_at' => now()->addMinutes(30),
                'pickup_attempts' => 0, 'updated_at' => now(),
            ]);
            $this->record($assignment->id, $request->user()->id, $assignment->courier_profile_id, 'code_issued');

            return $code;
        }, 3);

        return response()->json(['data' => ['pickup_code' => $code]]);
    }

    public function pickup(Request $request, int $assignment): JsonResponse
    {
        $courier = $this->courier($request);
        $input = $request->validate([
            'pickup_code' => ['required', 'digits:6'],
            'parcel_count' => ['required', 'integer', 'between:1,500'],
        ]);
        $status = DB::transaction(function () use ($request, $assignment, $courier, $input): string {
            $job = DB::table('courier_assignments')->where('id', $assignment)->lockForUpdate()->first();
            abort_unless($job && (int) $job->courier_profile_id === (int) $courier->id, 404);
            abort_unless($courier->approval_status === 'approved' && $job->status === 'accepted', 409);
            $order = DB::table('suborders')->where('id', $job->suborder_id)->lockForUpdate()->first();
            abort_unless($order && $order->status === 'ready', 409, 'Seller preparation is not ready.');
            abort_unless($job->pickup_code_hash && Carbon::parse($job->pickup_code_expires_at)->isFuture()
                && $job->pickup_attempts < 5, 409, 'Pickup code expired or locked.');
            if (! Hash::check($input['pickup_code'], $job->pickup_code_hash)) {
                DB::table('courier_assignments')->where('id', $job->id)
                    ->update(['pickup_attempts' => $job->pickup_attempts + 1, 'updated_at' => now()]);

                return 'invalid_code';
            }
            DB::table('courier_assignments')->where('id', $job->id)->update([
                'status' => 'picked_up', 'picked_up_at' => now(),
                'parcel_count' => $input['parcel_count'], 'pickup_code_hash' => null,
                'pickup_code_encrypted' => null, 'updated_at' => now(),
            ]);
            DB::table('suborders')->where('id', $job->suborder_id)->update([
                'status' => 'on_the_way', 'picked_up_at' => now(), 'updated_at' => now(),
            ]);
            $this->record($job->id, $request->user()->id, $courier->id, 'picked_up');
            DB::table('order_events')->insert([
                'order_id' => $order->order_id, 'suborder_id' => $order->id,
                'type' => 'courier_picked_up', 'actor' => 'courier',
                'note' => 'Seller pickup verified', 'meta' => json_encode(['parcel_count' => $input['parcel_count']]),
                'created_at' => now(),
            ]);
            $orderRecord = DB::table('orders')->where('id', $order->order_id)->first();
            app(OrderProgressNotifier::class)->record($orderRecord, $order, 'courier_picked_up', $request->user()->id);

            return 'picked_up';
        }, 3);
        abort_if($status === 'invalid_code', 422, 'Incorrect pickup code.');

        return response()->json(['data' => ['status' => $status]]);
    }

    /**
     * Record a failed handover without falsely marking the parcel as delivered.
     * An unresolved incident blocks repeated reporting of the same failure.
     */
    public function failedDelivery(Request $request, int $assignment): JsonResponse
    {
        $courier = $this->courier($request);
        abort_unless($courier->approval_status === 'approved', 403);
        $data = $request->validate([
            'reason' => ['required', Rule::in(['customer_absent', 'address_issue', 'customer_refused', 'other'])],
            'description' => ['required', 'string', 'between:10,1000'],
        ]);

        $incidentId = DB::transaction(function () use ($request, $assignment, $courier, $data): int {
            $job = DB::table('courier_assignments')
                ->where('id', $assignment)->lockForUpdate()->first();
            abort_unless($job && (int) $job->courier_profile_id === (int) $courier->id, 404);
            abort_unless($job->status === 'picked_up', 409, 'Only picked-up jobs can report failed delivery.');

            $suborder = DB::table('suborders')->where('id', $job->suborder_id)->lockForUpdate()->first();
            abort_unless($suborder && $suborder->status === 'on_the_way', 409);

            $alreadyOpen = DB::table('delivery_incidents')
                ->where('suborder_id', $job->suborder_id)
                ->where('type', 'delivery_issue')
                ->where('status', '!=', 'resolved')->exists();
            abort_if($alreadyOpen, 409, 'An unresolved delivery incident already exists.');

            $id = DB::table('delivery_incidents')->insertGetId([
                'suborder_id' => $job->suborder_id,
                'reported_by' => $request->user()->id,
                'assigned_to' => null,
                'type' => 'delivery_issue',
                'status' => 'open',
                'description' => $data['reason'].': '.$data['description'],
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->record($job->id, $request->user()->id, $courier->id, 'delivery_failed', $data['reason']);
            DB::table('order_events')->insert([
                'order_id' => $suborder->order_id, 'suborder_id' => $suborder->id,
                'type' => 'courier_delivery_failed', 'actor' => 'courier',
                'note' => 'Delivery attempt failed: '.$data['reason'],
                'meta' => json_encode(['incident_id' => $id]),
                'created_at' => now(),
            ]);
            $orderRecord = DB::table('orders')->where('id', $suborder->order_id)->first();
            app(OrderProgressNotifier::class)->record($orderRecord, $suborder, 'courier_delivery_failed', $request->user()->id);

            return $id;
        }, 3);

        return response()->json(['data' => ['incident_id' => $incidentId, 'status' => 'open']], 201);
    }

    public function history(Request $request, string $suborder): JsonResponse
    {
        $this->scopedOrder($request, $suborder);
        $events = DB::table('courier_assignment_events as e')
            ->join('courier_assignments as a', 'a.id', '=', 'e.assignment_id')
            ->where('a.suborder_id', $suborder)
            ->select('e.action', 'e.reason', 'e.actor_user_id', 'e.courier_profile_id', 'e.created_at')
            ->orderBy('e.id')->limit(100)->get();

        return response()->json(['data' => $events]);
    }

    public function incidentIndex(Request $request): JsonResponse
    {
        $ids = $this->permittedSellers($request);
        abort_if($ids === [], 403);
        $rows = DB::table('delivery_incidents as i')->join('suborders as s', 's.id', '=', 'i.suborder_id')
            ->whereIn('s.seller_id', $ids)->select('i.*')->orderByDesc('i.id')->limit(100)->get();

        return response()->json(['data' => $rows]);
    }

    public function incidentCreate(Request $request): JsonResponse
    {
        abort_if($this->permittedSellers($request) === [], 403);
        $data = $request->validate([
            'suborder_id' => ['required', 'ulid'],
            'type' => ['required', Rule::in(['complaint', 'refund', 'delivery_issue'])],
            'description' => ['required', 'string', 'between:10,2000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $this->scopedOrder($request, $data['suborder_id']);
        if (! empty($data['assigned_to'])) {
            abort_unless(DB::table('platform_admin_access')->where('user_id', $data['assigned_to'])
                ->where('is_active', true)->where('can_view_orders', true)
                ->where('seller_id', DB::table('suborders')->where('id', $data['suborder_id'])->value('seller_id'))
                ->exists(), 422, 'Incident owner must be an Admin for this seller.');
        }
        $id = DB::table('delivery_incidents')->insertGetId($data + [
            'reported_by' => $request->user()->id, 'status' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function incidentUpdate(Request $request, int $incident): JsonResponse
    {
        abort_if($this->permittedSellers($request) === [], 403);
        $data = $request->validate([
            'status' => ['required', Rule::in(['open', 'investigating', 'resolved'])],
            'resolution' => ['nullable', 'string', 'max:2000'],
        ]);
        $record = DB::table('delivery_incidents')->where('id', $incident)->first();
        abort_unless($record, 404);
        $this->scopedOrder($request, $record->suborder_id);
        abort_if($data['status'] === 'resolved' && empty(trim((string) ($data['resolution'] ?? ''))),
            422, 'Resolution is required.');
        DB::table('delivery_incidents')->where('id', $incident)->update($data + [
            'resolved_at' => $data['status'] === 'resolved' ? now() : null,
            'updated_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $incident, 'status' => $data['status']]]);
    }

    public function daily(Request $request): JsonResponse
    {
        $ids = $this->permittedSellers($request);
        abort_if($ids === [], 403);
        $today = now()->toDateString();
        $cases = DB::table('delivery_incidents as i')->join('suborders as s', 's.id', '=', 'i.suborder_id')
            ->whereIn('s.seller_id', $ids);
        $orders = DB::table('suborders')->whereIn('seller_id', $ids);

        return response()->json(['data' => [
            'day' => $today,
            'open_incidents' => (clone $cases)->where('i.status', '!=', 'resolved')->count(),
            'resolved_today' => (clone $cases)->whereDate('i.resolved_at', $today)->count(),
            'ready_orders' => (clone $orders)->where('status', 'ready')->count(),
            'picked_up_today' => (clone $orders)->whereDate('picked_up_at', $today)->count(),
        ]]);
    }
}
