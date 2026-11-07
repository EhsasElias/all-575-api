<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Seller staff directory. Only an active owner can inspect memberships.
 * No customer contact details or credentials are returned.
 */
final class SellerStaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sellerId = (int) $filters['seller_id'];
        $this->ensureOwner($request, $sellerId);

        $members = DB::table('seller_staff_access')
            ->join('users', 'users.id', '=', 'seller_staff_access.user_id')
            ->where('seller_staff_access.seller_id', $sellerId)
            ->select('seller_staff_access.id', 'seller_staff_access.user_id',
                'seller_staff_access.employee_number', 'seller_staff_access.seller_id',
                'seller_staff_access.branch_id', 'seller_staff_access.role',
                'seller_staff_access.is_active', 'users.name')
            ->orderBy('seller_staff_access.id')
            ->limit($filters['limit'] ?? 50)
            ->get();

        return response()->json(['data' => $members]);
    }

    public function deactivate(Request $request, int $membership): JsonResponse
    {
        $result = DB::transaction(function () use ($request, $membership): array {
            $target = DB::table('seller_staff_access')
                ->where('id', $membership)->lockForUpdate()->first();

            abort_if($target === null, 404);
            $this->ensureOwner($request, (int) $target->seller_id);

            // Owners cannot disable other owners or themselves from this endpoint.
            // Changes of ownership must use a separate administrative review.
            abort_if($target->role === 'owner', 403, 'Ownership changes require administrative review.');

            if ($target->is_active) {
                DB::table('seller_staff_access')->where('id', $target->id)
                    ->update(['is_active' => false]);
            }

            return ['id' => $target->id, 'employee_number' => $target->employee_number, 'is_active' => false];
        }, 3);

        return response()->json(['data' => $result]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['required', 'integer', 'min:1', 'exists:users,id'],
            'role' => ['required', 'in:branch_manager,picker'],
            'branch_id' => ['required', 'integer', 'min:1'],
        ]);
        $sellerId = (int) $input['seller_id'];
        $this->ensureOwner($request, $sellerId);
        $this->ensureBranch($sellerId, (int) $input['branch_id']);
        abort_unless(DB::table('users')->where('id', $input['user_id'])->exists(), 422);

        $result = DB::transaction(function () use ($input, $sellerId): array {
            // Serialize numbering within a store. Concurrent invitations cannot share a number.
            DB::table('sellers')->where('id', $sellerId)->lockForUpdate()->first();
            $existing = DB::table('seller_staff_access')
                ->where('seller_id', $sellerId)->where('user_id', $input['user_id'])
                ->lockForUpdate()->first();
            abort_if($existing !== null, 409, 'This account already has store access. Edit its existing membership instead.');

            $lastNumber = DB::table('seller_staff_access')->where('seller_id', $sellerId)
                ->whereNotNull('employee_number')->orderByDesc('employee_number')
                ->value('employee_number');
            $sequence = $lastNumber ? (int) substr((string) $lastNumber, 4) : 0;
            abort_if($sequence >= 999999, 422, 'Employee number range exhausted.');
            $employeeNumber = sprintf('EMP-%06d', $sequence + 1);

            $id = DB::table('seller_staff_access')->insertGetId([
                'seller_id' => $sellerId,
                'user_id' => (int) $input['user_id'],
                'employee_number' => $employeeNumber,
                'branch_id' => (int) $input['branch_id'],
                'role' => $input['role'],
                'is_active' => true,
            ]);
            return ['id' => $id, 'user_id' => (int) $input['user_id'],
                'employee_number' => $employeeNumber,
                'seller_id' => $sellerId, 'branch_id' => (int) $input['branch_id'],
                'role' => $input['role'], 'is_active' => true];
        }, 3);
        return response()->json(['data' => $result], 201);
    }

    public function update(Request $request, int $membership): JsonResponse
    {
        $input = $request->validate([
            'role' => ['required', 'in:branch_manager,picker'],
            'branch_id' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);
        $result = DB::transaction(function () use ($request, $membership, $input): array {
            $target = DB::table('seller_staff_access')->where('id', $membership)
                ->lockForUpdate()->first();
            abort_if($target === null, 404);
            $this->ensureOwner($request, (int) $target->seller_id);
            abort_if($target->role === 'owner', 403, 'Owners cannot be changed here.');
            $this->ensureBranch((int) $target->seller_id, (int) $input['branch_id']);

            DB::table('seller_staff_access')->where('id', $target->id)->update([
                'role' => $input['role'],
                'branch_id' => (int) $input['branch_id'],
                'is_active' => (bool) $input['is_active'],
            ]);
            return ['id' => (int) $target->id, 'user_id' => (int) $target->user_id,
                'employee_number' => $target->employee_number,
                'seller_id' => (int) $target->seller_id,
                'role' => $input['role'], 'branch_id' => (int) $input['branch_id'],
                'is_active' => (bool) $input['is_active']];
        }, 3);
        return response()->json(['data' => $result]);
    }

    private function ensureBranch(int $sellerId, int $branchId): void
    {
        abort_unless(DB::table('branches')
            ->where('id', $branchId)->where('seller_id', $sellerId)
            ->where('is_active', true)->exists(), 422, 'Choose an active branch from this store.');
    }

    private function ensureOwner(Request $request, int $sellerId): void
    {
        $allowed = DB::table('sellers')->where('id', $sellerId)
            ->where('status', 'approved')
            ->whereExists(function ($query) use ($request): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'sellers.id')
                    ->where('seller_staff_access.user_id', $request->user()->id)
                    ->where('seller_staff_access.role', 'owner')
                    ->whereNull('seller_staff_access.branch_id')
                    ->where('seller_staff_access.is_active', true);
            })->exists();

        abort_unless($allowed, 404);
    }
}
