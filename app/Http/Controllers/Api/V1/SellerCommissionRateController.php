<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SellerCommissionRateController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['seller_id' => ['required', 'integer', 'min:1']]);
        $sellerId = (int) $data['seller_id'];

        $authorized = DB::table('seller_staff_access')
            ->join('sellers', 'sellers.id', '=', 'seller_staff_access.seller_id')
            ->where('seller_staff_access.seller_id', $sellerId)
            ->where('seller_staff_access.user_id', $request->user()->id)
            ->where('seller_staff_access.role', 'owner')
            ->where('seller_staff_access.is_active', true)
            ->whereNull('seller_staff_access.branch_id')
            ->where('sellers.status', 'approved')
            ->exists();

        abort_unless($authorized, 404);

        $history = DB::table('seller_commission_rates')
            ->where('seller_id', $sellerId)
            ->whereNotNull('approved_at')
            ->where('effective_from', '<=', now())
            ->select('id', 'rate_basis_points', 'effective_from', 'approved_at')
            ->orderByDesc('effective_from')
            ->limit(30)
            ->get();

        return response()->json(['data' => [
            'seller_id' => $sellerId,
            'current_rate_basis_points' => $history->first()?->rate_basis_points,
            'approved_history' => $history,
            'payout_calculated' => false,
        ]]);
    }
}
