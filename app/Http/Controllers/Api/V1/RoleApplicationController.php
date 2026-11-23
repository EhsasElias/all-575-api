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
            ->select('id', 'requested_role', 'status', 'vehicle_type', 'motivation', 'created_at')
            ->orderByDesc('id')->get()]);
    }

    public function submit(Request $request): JsonResponse
    {
        $input = $request->validate([
            'requested_role' => ['required', Rule::in(['admin', 'courier'])],
            'motivation' => ['required', 'string', 'min:10', 'max:1000'],
            'vehicle_type' => ['required_if:requested_role,courier', 'nullable',
                Rule::in(['motorcycle', 'car', 'van', 'bicycle'])],
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

    public function reviewQueue(Request $request): JsonResponse
    {
        $owner = DB::table('platform_super_admin_grants')
            ->where('user_id', $request->user()->id)->where('is_active', true)->exists();
        abort_unless($request->user()->is_active && $owner, 403);

        return response()->json(['data' => DB::table('role_applications as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->select('a.id', 'a.user_id', 'u.name', 'u.email', 'a.requested_role',
                'a.status', 'a.vehicle_type', 'a.motivation', 'a.created_at')
            ->where('a.status', 'pending')->orderByDesc('a.id')->limit(100)->get()]);
    }
}
