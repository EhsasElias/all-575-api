<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notifications\RoleNotificationWriter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class RoleApplicationController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('role_applications')
            ->where('user_id', $request->user()->id)
            ->select('id', 'requested_role', 'status', 'vehicle_type', 'motivation',
                'contact_phone', 'city', 'experience_years', 'qualification',
                'service_area', 'vehicle_plate_number', 'created_at')
            ->orderByDesc('id')->get()]);
    }

    public function submit(Request $request): JsonResponse
    {
        $input = $request->validate([
            'requested_role' => ['required', Rule::in(['admin', 'courier'])],
            'motivation' => ['required', 'string', 'min:10', 'max:1000'],
            'vehicle_type' => ['required_if:requested_role,courier', 'nullable',
                Rule::in(['motorcycle', 'car', 'van', 'bicycle'])],
            'contact_phone' => ['sometimes', 'nullable', 'regex:/^05[0-9]{8}$/'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'experience_years' => ['sometimes', 'nullable', 'integer', 'between:0,60'],
            'qualification' => ['sometimes', 'nullable', 'string', 'max:180'],
            'service_area' => ['sometimes', 'nullable', 'string', 'max:180'],
            'vehicle_plate_number' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        abort_unless($request->user()->is_active && $request->user()->isVerified(), 403);

        $result = DB::transaction(function () use ($request, $input): object {
            $row = DB::table('role_applications')
                ->where('user_id', $request->user()->id)
                ->where('requested_role', $input['requested_role'])
                ->lockForUpdate()->first();
            abort_if($row !== null, 409, 'An application already exists for this role.');

            $id = DB::table('role_applications')->insertGetId([
                'user_id' => $request->user()->id,
                'requested_role' => $input['requested_role'],
                'status' => 'pending',
                'vehicle_type' => $input['requested_role'] === 'courier' ? $input['vehicle_type'] : null,
                'motivation' => trim($input['motivation']),
                'contact_phone' => $input['contact_phone'] ?? null,
                'city' => $input['city'] ?? null,
                'experience_years' => $input['experience_years'] ?? null,
                'qualification' => $input['requested_role'] === 'admin' ? ($input['qualification'] ?? null) : null,
                'service_area' => $input['requested_role'] === 'courier' ? ($input['service_area'] ?? null) : null,
                'vehicle_plate_number' => $input['requested_role'] === 'courier' ? ($input['vehicle_plate_number'] ?? null) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('role_applications')
                ->where('id', $id)
                ->first(['id', 'requested_role', 'status', 'vehicle_type', 'created_at']);
        }, 3);

        $roleLabel = $input['requested_role'] === 'admin' ? 'مسؤول' : 'مندوب توصيل';
        $superAdminIds = DB::table('platform_super_admin_grants as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.is_active', true)
            ->where('u.is_active', true)
            ->distinct()->pluck('g.user_id');
        $notifier = app(RoleNotificationWriter::class);
        foreach ($superAdminIds as $superAdminId) {
            $notifier->send(
                (int) $superAdminId,
                'role_application_submitted',
                'طلب انضمام جديد',
                "تم تقديم طلب انضمام ك{$roleLabel} رقم {$result->id} ويحتاج إلى مراجعتك.",
                '/account/super-admin#role-applications',
                'role_application_submitted:'.$result->id,
            );
        }

        // Applicants are never granted either permission by submitting this request.
        // Admin grants require a Super Admin and an assigned seller; courier registration
        // and approval use the separate protected courier review workflow.
        return response()->json(['data' => $result], 201);
    }

    public function review(Request $request, int $application): JsonResponse
    {
        $owner = DB::table('platform_super_admin_grants')
            ->where('user_id', $request->user()->id)->where('is_active', true)->exists();
        abort_unless($request->user()->is_active && $owner, 403);

        $input = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = DB::transaction(function () use ($request, $application, $input): object {
            $row = DB::table('role_applications')->where('id', $application)
                ->lockForUpdate()->first();
            abort_if($row === null, 404);
            abort_if($row->status !== 'pending', 409, 'This application was already reviewed.');

            DB::table('role_applications')->where('id', $application)->update([
                'status' => $input['decision'],
                'reviewed_by' => $request->user()->id,
                'review_note' => trim($input['note'] ?? ''),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            return DB::table('role_applications')->where('id', $application)
                ->first(['id', 'requested_role', 'status', 'reviewed_at']);
        }, 3);

        $applicantId = DB::table('role_applications')->where('id', $application)->value('user_id');
        if ($applicantId !== null) {
            $approved = $input['decision'] === 'approved';
            app(RoleNotificationWriter::class)->send(
                (int) $applicantId,
                $approved ? 'role_application_approved' : 'role_application_rejected',
                $approved ? 'تمت الموافقة على طلب الانضمام' : 'تم رفض طلب الانضمام',
                $approved
                    ? 'تمت مراجعة طلب انضمامك والموافقة عليه. لا تُمنح صلاحيات العمل إلا بعد تفعيلها بشكل منفصل.'
                    : 'تمت مراجعة طلب انضمامك ورفضه. يمكنك متابعة حالة الطلب من حسابك.',
                $result->requested_role === 'admin' ? '/account/apply/admin' : '/account/apply/courier',
                'role_application_reviewed:'.$application,
            );
        }

        // Reviewing a request never grants admin access or activates a courier.
        return response()->json(['data' => $result]);
    }

    /**
     * Provision an explicitly approved application with a concrete seller or zone.
     * Approval alone does not grant access. Only a Super Admin may activate it.
     */
    public function activate(Request $request, int $application): JsonResponse
    {
        $owner = DB::table('platform_super_admin_grants')
            ->where('user_id', $request->user()->id)->where('is_active', true)->exists();
        abort_unless($request->user()->is_active && $owner, 403);

        $input = $request->validate([
            'seller_id' => ['required_if:requested_role,admin', 'nullable', 'integer', 'exists:sellers,id'],
            'service_zone_id' => ['nullable', 'integer', 'exists:service_zones,id'],
            'can_view_orders' => ['sometimes', 'boolean'],
            'can_view_inventory' => ['sometimes', 'boolean'],
        ]);

        $result = DB::transaction(function () use ($request, $application, $input): array {
            $row = DB::table('role_applications')->where('id', $application)
                ->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless($row->status === 'approved', 409, 'Review and approve the request first.');
            $user = DB::table('users')->where('id', $row->user_id)->first();
            abort_unless($user && $user->is_active && $user->email_verified_at, 422,
                'An active, email-verified applicant is required.');

            if ($row->requested_role === 'admin') {
                abort_unless(isset($input['seller_id']), 422, 'Select a seller for the Admin.');
                abort_unless(DB::table('sellers')->where('id', $input['seller_id'])
                    ->where('status', 'approved')->exists(), 422, 'Choose an approved seller.');
                abort_if(DB::table('platform_super_admin_grants')->where('user_id', $row->user_id)
                    ->where('is_active', true)->exists(), 422, 'Cannot assign Super Admin using Admin permissions.');
                abort_if(DB::table('platform_admin_access')->where('user_id', $row->user_id)
                    ->where('seller_id', $input['seller_id'])->exists(), 409, 'Admin access already assigned.');
                $orders = $input['can_view_orders'] ?? true;
                $inventory = $input['can_view_inventory'] ?? false;
                abort_unless($orders || $inventory, 422, 'Select at least one permission.');
                $id = DB::table('platform_admin_access')->insertGetId([
                    'user_id' => $row->user_id,
                    'seller_id' => $input['seller_id'],
                    'is_active' => true,
                    'can_view_orders' => $orders,
                    'can_view_inventory' => $inventory,
                    'can_review_sellers' => false,
                    'granted_by' => $request->user()->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return ['role' => 'admin', 'access_id' => $id];
            }

            abort_unless($row->requested_role === 'courier', 422, 'Unsupported role.');
            abort_unless(isset($input['service_zone_id']), 422, 'Select a delivery zone.');
            abort_unless(DB::table('service_zones')->where('id', $input['service_zone_id'])
                ->where('is_active', true)->exists(), 422, 'Choose an active delivery zone.');
            abort_if(DB::table('courier_profiles')->where('user_id', $row->user_id)->exists(),
                409, 'Courier profile already exists; use Courier approvals to review it.');
            $id = DB::table('courier_profiles')->insertGetId([
                'user_id' => $row->user_id,
                'service_zone_id' => $input['service_zone_id'],
                'vehicle_type' => $row->vehicle_type,
                'approval_status' => 'approved',
                'is_online' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['role' => 'courier', 'courier_profile_id' => $id];
        }, 3);

        app(RoleNotificationWriter::class)->send(
            (int) DB::table('role_applications')->where('id', $application)->value('user_id'),
            $result['role'] === 'admin' ? 'admin_assigned' : 'courier_approved',
            'تم تفعيل صلاحية العمل',
            $result['role'] === 'admin'
                ? 'تم تفعيل صلاحيات الإدارة للمتجر المحدد.'
                : 'تم اعتماد ملف المندوب. يمكنك الآن استقبال مهام التوصيل.',
            $result['role'] === 'admin' ? '/account/admin' : '/account/courier',
            'role_application_activated:'.$application,
        );

        return response()->json(['data' => $result], 201);
    }

    public function deliveryZones(Request $request): JsonResponse
    {
        $owner = DB::table('platform_super_admin_grants')
            ->where('user_id', $request->user()->id)->where('is_active', true)->exists();
        abort_unless($request->user()->is_active && $owner, 403);

        return response()->json(['data' => DB::table('service_zones')
            ->where('is_active', true)
            ->select('id', 'name_ar', 'name_en')
            ->orderBy('id')->limit(250)->get()]);
    }

    public function reviewQueue(Request $request): JsonResponse
    {
        $owner = DB::table('platform_super_admin_grants')
            ->where('user_id', $request->user()->id)->where('is_active', true)->exists();
        abort_unless($request->user()->is_active && $owner, 403);

        return response()->json(['data' => DB::table('role_applications as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->select('a.id', 'a.user_id', 'u.name', 'u.email', 'a.requested_role',
                'a.status', 'a.vehicle_type', 'a.motivation', 'a.created_at',
                'a.contact_phone', 'a.city', 'a.experience_years',
                'a.qualification', 'a.service_area', 'a.vehicle_plate_number')
            ->whereIn('a.status', ['pending', 'approved'])->orderByDesc('a.id')->limit(100)->get()
            ->map(function ($item): object {
                $item->activated = $item->requested_role === 'admin'
                    ? DB::table('platform_admin_access')->where('user_id', $item->user_id)
                        ->where('is_active', true)->exists()
                    : DB::table('courier_profiles')->where('user_id', $item->user_id)
                        ->where('approval_status', 'approved')->exists();

                return $item;
            })]);
    }
}
