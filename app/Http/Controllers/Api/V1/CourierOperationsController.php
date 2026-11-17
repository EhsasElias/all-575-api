<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Domain\Orders\Models\Suborder;
use App\Domain\Orders\Models\DeliveryCode;
use App\Domain\Orders\Services\DeliveryCodes;
use App\Domain\Notifications\CourierMilestoneNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        abort_unless($request->user()->is_active && $request->user()->isVerified(), 403);
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
        abort_if(!$isOwner && $this->permittedSellers($request) === [], 403);
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
        abort_unless(DB::table('service_zones')->where('id', $data['service_zone_id'])->where('is_active', true)->exists(),
            422, 'Courier service zone must be active.');
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
        abort_unless($courier->approval_status === 'approved' &&
            DB::table('service_zones')->where('id', $courier->service_zone_id)->where('is_active', true)->exists(),
            403, 'Courier must be approved and assigned to an active service zone.');
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
                && $order->paid_at !== null && in_array($order->order_status, ['confirmed', 'completed'], true)
                && DB::table('payments')->where('order_id', $order->order_id)
                    ->where('status', 'paid')->exists(),
                409, 'Verified payment and seller readiness are required before dispatch.');
            $courier = DB::table('courier_profiles')->where('id', $input['courier_profile_id'])->first();
            abort_unless($courier && $courier->approval_status === 'approved'
                && (bool) $courier->is_online && (int) $courier->service_zone_id === (int) $order->service_zone_id,
                422, 'Courier must be approved, online, and in the order service zone.');
            $existing = DB::table('courier_assignments')->where('suborder_id', $suborder)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(in_array($existing->status, ['declined', 'expired', 'offered'], true), 409,
                    'Accepted or picked-up assignments cannot be replaced.');
                abort_if($existing->status === 'offered' && \Illuminate\Support\Carbon::parse($existing->expires_at)->isFuture()
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
                'parcel_count' => null, 'picked_up_at' => null, 'updated_at' => now(),
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
                'a.accepted_at', 'a.picked_up_at', 'a.parcel_count',
                'o.number as order_number', 's.branch_id', 'b.lat as pickup_lat',
                'b.lng as pickup_lng', 's.status as suborder_status')
            ->orderByDesc('a.id')->limit(100)->get();
        // Expired offers are displayed as expired even before a worker sweeps the database.
        $jobs->transform(function (object $job): object {
            if ($job->status === 'offered' && $job->expires_at !== null
                && \Illuminate\Support\Carbon::parse($job->expires_at)->isPast()) {
                $job->status = 'expired';
            }
            return $job;
        });
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
            if (\Illuminate\Support\Carbon::parse($job->expires_at)->isPast()) {
                DB::table('courier_assignments')->where('id', $job->id)
                    ->update(['status' => 'expired', 'updated_at' => now()]);
                $this->record($job->id, $request->user()->id, $courier->id, 'expired');
                return 'expired';
            }
            if ($data['decision'] === 'accept') {
                abort_unless((bool) $courier->is_online &&
                    DB::table('service_zones')->where('id', $courier->service_zone_id)
                        ->where('is_active', true)->exists(), 409, 'Go online in an active zone to accept.');
                abort_unless(DB::table('suborders')->where('id', $job->suborder_id)
                    ->where('status', 'ready')->exists(), 409, 'This order is no longer ready for pickup.');
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
            abort_if($assignment->pickup_attempts >= 5 && $assignment->pickup_code_expires_at &&
                \Illuminate\Support\Carbon::parse($assignment->pickup_code_expires_at)->isFuture(),
                429, 'Pickup code locked after too many attempts. Contact support.');
            if ($assignment->pickup_code_encrypted && \Illuminate\Support\Carbon::parse($assignment->pickup_code_expires_at)->isFuture()) {
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
            abort_unless($request->user()->isVerified(), 403);
            $order = DB::table('suborders')->where('id', $job->suborder_id)->lockForUpdate()->first();
            abort_unless($order && $order->status === 'ready', 409, 'Seller preparation is not ready.');
            abort_unless($job->pickup_code_hash && \Illuminate\Support\Carbon::parse($job->pickup_code_expires_at)->isFuture()
                && $job->pickup_attempts < 5, 409, 'Pickup code expired or locked.');
            if (!Hash::check($input['pickup_code'], $job->pickup_code_hash)) {
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
            // Separate customer hand-over code is issued only after verified seller pickup.
            app(DeliveryCodes::class)->issue(Suborder::query()->findOrFail($job->suborder_id));
            $this->record($job->id, $request->user()->id, $courier->id, 'picked_up');
            DB::table('order_events')->insert([
                'order_id' => $order->order_id, 'suborder_id' => $order->id,
                'type' => 'courier_picked_up', 'actor' => 'courier',
                'note' => 'Seller pickup verified', 'meta' => json_encode(['parcel_count' => $input['parcel_count']]),
                'created_at' => now(),
            ]);
            DB::afterCommit(fn () => app(CourierMilestoneNotifications::class)->send($order->id, 'picked_up'));
            return 'picked_up';
        }, 3);
        abort_if($status === 'invalid_code', 422, 'Incorrect pickup code.');
        return response()->json(['data' => ['status' => $status]]);
    }

    public function deliver(Request $request, int $assignment): JsonResponse
    {
        $courier = $this->courier($request);
        abort_unless($courier->approval_status === 'approved', 403);
        $input = $request->validate(['delivery_code' => ['required', 'digits:6']]);

        // Verifying the existing customer-specific delivery code and state transition
        // occur in one transaction: invalid codes never mark a delivery as completed.
        $outcome = DB::transaction(function () use ($request, $courier, $assignment, $input): array {
            $job = DB::table('courier_assignments')->where('id', $assignment)->lockForUpdate()->first();
            abort_unless($job && (int) $job->courier_profile_id === (int) $courier->id, 404);
            abort_unless($job->status === 'picked_up', 409, 'Seller pickup has not been verified.');
            $suborder = Suborder::query()->lockForUpdate()->findOrFail($job->suborder_id);
            abort_unless($suborder->status->value === 'on_the_way', 409, 'Suborder not in transit.');
            // Keep failed-attempt increments committed: an exception inside this outer
            // transaction would roll them back, allowing unlimited guesses.
            $code = DeliveryCode::query()->where('suborder_id', $suborder->id)
                ->lockForUpdate()->first();
            if ($code === null || $code->verified_at !== null || $code->expires_at->isPast()) {
                return ['status' => 'code_unavailable'];
            }
            if ($code->attempts >= $code->max_attempts) {
                return ['status' => 'code_locked'];
            }
            if (!hash_equals($code->code_encrypted, $input['delivery_code'])) {
                $code->increment('attempts');
                return ['status' => 'incorrect_code',
                    'attempts_remaining' => max(0, $code->max_attempts - $code->attempts)];
            }
            $code->forceFill(['verified_at' => now()])->save();

            $suborder->forceFill(['status' => 'delivered', 'delivered_at' => now()])->save();
            DB::table('courier_assignments')->where('id', $job->id)->update([
                'status' => 'delivered', 'updated_at' => now(),
            ]);
            $this->record($job->id, $request->user()->id, $courier->id, 'delivered');
            $order = DB::table('orders')->where('id', $suborder->order_id)->lockForUpdate()->first();
            DB::table('order_events')->insert([
                'order_id' => $suborder->order_id, 'suborder_id' => $suborder->id,
                'type' => 'courier_delivered', 'actor' => 'courier',
                'note' => 'Customer handover code verified', 'created_at' => now(),
            ]);
            DB::afterCommit(fn () => app(CourierMilestoneNotifications::class)->send($suborder->id, 'delivered'));
            $remaining = DB::table('suborders')->where('order_id', $suborder->order_id)
                ->whereNotIn('status', ['delivered', 'cancelled'])->exists();
            if (!$remaining && $order && $order->status === 'confirmed') {
                DB::table('orders')->where('id', $order->id)->update([
                    'status' => 'completed', 'updated_at' => now(),
                ]);
            }
            return ['status' => 'delivered', 'order_id' => $suborder->order_id];
        }, 3);
        abort_if($outcome['status'] === 'code_unavailable', 409, 'Delivery code expired or unavailable.');
        abort_if($outcome['status'] === 'code_locked', 429, 'Delivery code locked. Contact support.');
        abort_if($outcome['status'] === 'incorrect_code', 422, 'Incorrect delivery code.');
        return response()->json(['data' => $outcome]);
    }

    public function failedDelivery(Request $request, int $assignment): JsonResponse
    {
        $courier = $this->courier($request);
        abort_unless($courier->approval_status === 'approved', 403);
        $data = $request->validate([
            'reason' => ['required', Rule::in(['customer_absent', 'address_issue', 'customer_refused', 'return_required', 'other'])],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $response = DB::transaction(function () use ($request, $courier, $assignment, $data): array {
            $job = DB::table('courier_assignments')->where('id', $assignment)->lockForUpdate()->first();
            abort_unless($job && (int) $job->courier_profile_id === (int) $courier->id, 404);
            abort_unless($job->status === 'picked_up', 409, 'Only in-transit jobs can report failed delivery.');
            $order = DB::table('suborders')->where('id', $job->suborder_id)
                ->where('status', 'on_the_way')->first(['id', 'order_id']);
            abort_unless($order, 409);
            $attempt = DB::table('courier_delivery_attempts')->insertGetId([
                'assignment_id' => $job->id, 'courier_profile_id' => $courier->id,
                'reason' => $data['reason'], 'notes' => $data['notes'] ?? null,
                'attempted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('delivery_incidents')->insert([
                'suborder_id' => $order->id, 'reported_by' => $request->user()->id,
                'type' => 'delivery_issue', 'status' => 'open',
                'description' => 'Delivery failed: '.$data['reason'].(!empty($data['notes']) ? ' - '.$data['notes'] : ''),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->record($job->id, $request->user()->id, $courier->id, 'delivery_failed', $data['reason']);
            DB::table('order_events')->insert([
                'order_id' => $order->order_id, 'suborder_id' => $order->id,
                'type' => 'delivery_attempt_failed', 'actor' => 'courier',
                'note' => $data['reason'], 'created_at' => now(),
            ]);
            DB::afterCommit(fn () => app(CourierMilestoneNotifications::class)->send($order->id, 'delivery_failed'));
            // Do not mark returned/delivered without an approved follow-up process.
            return ['attempt_id' => $attempt, 'status' => 'needs_admin_review'];
        }, 3);
        return response()->json(['data' => $response], 201);
    }

    public function myHistory(Request $request): JsonResponse
    {
        $courier = $this->courier($request);
        $rows = DB::table('courier_assignments as a')
            ->join('suborders as s', 's.id', '=', 'a.suborder_id')
            ->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('a.courier_profile_id', $courier->id)
            ->whereIn('a.status', ['declined', 'expired', 'delivered'])
            ->select('a.id', 'a.status', 'a.accepted_at', 'a.picked_up_at',
                'a.parcel_count', 'o.number as order_number')
            ->orderByDesc('a.updated_at')->limit(100)->get();
        return response()->json(['data' => $rows, 'earnings' => null,
            'earnings_note' => 'Courier earnings are not configured.']);
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
        if (!empty($data['assigned_to'])) {
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
