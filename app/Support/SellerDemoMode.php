<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Explicit opt-in for demo-only seller operations. Never grants reviewer access.
 * Bound to a single authenticated owner and store; disabled by default.
 */
final class SellerDemoMode
{
    public static function allows(Request $request, int $sellerId): bool
    {
        $user = $request->user();

        return config('store.demo_seller_direct_approval.enabled') === true
            && $user !== null
            && $user->is_active
            && (int) $user->id === (int) config('store.demo_seller_direct_approval.user_id')
            && $sellerId === (int) config('store.demo_seller_direct_approval.seller_id')
            && DB::table('sellers')->where('id', $sellerId)->where('status', 'approved')->exists()
            && DB::table('seller_staff_access')
                ->where('user_id', $user->id)
                ->where('seller_id', $sellerId)
                ->where('role', 'owner')
                ->where('is_active', true)
                ->whereNull('branch_id')
                ->exists();
    }
}
