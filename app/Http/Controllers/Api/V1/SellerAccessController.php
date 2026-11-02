<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerAccessController extends Controller
{
    /**
     * Exchange an authenticated customer session for a seller-scoped session.
     * A verified account never becomes a seller merely by requesting this endpoint.
     * Memberships are assigned and activated by a separately authorized approval process.
     */
    public function memberships(Request $request): JsonResponse
    {
        $memberships = DB::table('seller_staff_access as access')
            ->join('sellers', 'sellers.id', '=', 'access.seller_id')
            ->leftJoin('branches', 'branches.id', '=', 'access.branch_id')
            ->where('access.user_id', $request->user()->id)
            ->select('access.id', 'access.role', 'access.is_active', 'access.branch_id',
                'sellers.id as seller_id', 'sellers.name_ar', 'sellers.name_en',
                'sellers.status as seller_status', 'branches.name_ar as branch_name_ar',
                'branches.name_en as branch_name_en')
            ->orderBy('sellers.id')->get();

        return response()->json(['data' => $memberships]);
    }

    public function issue(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isVerified(), 403);

        $membership = DB::table('seller_staff_access as access')
            ->join('sellers', 'sellers.id', '=', 'access.seller_id')
            ->where('access.user_id', $user->id)
            ->where('access.is_active', true)
            ->where('sellers.status', 'approved')
            ->whereIn('access.role', ['owner', 'branch_manager', 'picker'])
            ->select('access.id')
            ->first();

        abort_unless($membership !== null, 403);

        $validated = $request->validate([
            'device_name' => ['required', 'string', 'max:100'],
        ]);

        $token = $user->createToken(
            $validated['device_name'].' (seller)',
            ['seller'],
            now()->addDays((int) config('store.sessions.ttl_days')),
        );

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'token_type' => 'Bearer',
            ],
        ]);
    }
}
