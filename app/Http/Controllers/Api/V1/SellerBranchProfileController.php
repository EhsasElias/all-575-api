<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Owners may edit display names of existing approved-store branches.
 * Branch locations, delivery zones and active state remain under administrator control.
 */
final class SellerBranchProfileController extends Controller
{
    public function update(Request $request, int $branch): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
        ]);

        $updated = DB::transaction(function () use ($request, $branch, $data): array {
            $row = DB::table('branches')
                ->join('sellers', 'sellers.id', '=', 'branches.seller_id')
                ->where('branches.id', $branch)
                ->where('sellers.status', 'approved')
                ->whereExists(function ($query) use ($request): void {
                    $query->selectRaw('1')->from('seller_staff_access')
                        ->whereColumn('seller_staff_access.seller_id', 'branches.seller_id')
                        ->where('seller_staff_access.user_id', $request->user()->id)
                        ->where('seller_staff_access.is_active', true)
                        ->where('seller_staff_access.role', 'owner')
                        ->whereNull('seller_staff_access.branch_id');
                })
                ->select('branches.id', 'branches.seller_id')
                ->lockForUpdate()
                ->firstOrFail();

            DB::table('branches')->where('id', $row->id)->update([
                'name_ar' => trim($data['name_ar']),
                'name_en' => trim($data['name_en']),
                'updated_at' => now(),
            ]);

            return [
                'id' => $row->id,
                'seller_id' => $row->seller_id,
                'name_ar' => trim($data['name_ar']),
                'name_en' => trim($data['name_en']),
            ];
        }, 3);

        return response()->json(['data' => $updated]);
    }
}
