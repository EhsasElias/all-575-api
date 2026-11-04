<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Update store display names; legal company details and approval remain immutable here. */
final class SellerStoreProfileController extends Controller
{
    public function show(Request $request, int $seller): JsonResponse
    {
        return response()->json(['data' => $this->authorizedStore($request, $seller)]);
    }

    public function update(Request $request, int $seller): JsonResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
        ]);
        abort_if(trim($data['name_ar']) === '' || trim($data['name_en']) === '', 422, 'Store names cannot be blank.');

        $profile = DB::transaction(function () use ($request, $seller, $data): object {
            $this->authorizedStore($request, $seller);

            DB::table('sellers')->where('id', $seller)->where('status', 'approved')
                ->update([
                    'name_ar' => trim($data['name_ar']),
                    'name_en' => trim($data['name_en']),
                    'updated_at' => now(),
                ]);

            return $this->authorizedStore($request, $seller);
        }, 3);

        return response()->json(['data' => $profile]);
    }

    private function authorizedStore(Request $request, int $seller): object
    {
        return DB::table('sellers')
            ->where('sellers.id', $seller)
            ->where('sellers.status', 'approved')
            ->whereExists(function ($query) use ($request): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'sellers.id')
                    ->where('seller_staff_access.user_id', $request->user()->id)
                    ->where('seller_staff_access.is_active', true)
                    ->where('seller_staff_access.role', 'owner')
                    ->whereNull('seller_staff_access.branch_id');
            })
            ->select('sellers.id', 'sellers.slug', 'sellers.name_ar', 'sellers.name_en', 'sellers.status')
            ->firstOrFail();
    }
}
