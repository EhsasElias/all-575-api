<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

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

        // Reviewing a request never grants admin access or activates a courier.
        return response()->json(['data' => $result]);
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
            ->where('a.status', 'pending')->orderByDesc('a.id')->limit(100)->get()]);
    }
}
