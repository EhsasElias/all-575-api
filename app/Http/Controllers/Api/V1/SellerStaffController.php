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
            ->where('seller_id', $sellerId)
            ->select('id', 'user_id', 'seller_id', 'branch_id', 'role', 'is_active')
            ->orderBy('id')
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

            return ['id' => $target->id, 'is_active' => false];
        }, 3);

        return response()->json(['data' => $result]);
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
