<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\ServiceZone;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Sellers can propose inactive branches. A separate reviewer must activate them. */
final class SellerBranchOnboardingController extends Controller
{
    public function serviceZones(Request $request): JsonResponse
    {
        return response()->json(['data' => ServiceZone::query()->active()
            ->select('id', 'name_ar', 'name_en', 'center_lat', 'center_lng', 'radius_meters')
            ->orderBy('id')->limit(100)->get()]);
    }

    public function create(Request $request): JsonResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code', '')))]);
        $input = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'service_zone_id' => ['required', 'integer', Rule::exists('service_zones', 'id')->where('is_active', true)],
            'code' => ['required', 'string', 'min:2', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('branches', 'code')],
            'name_ar' => ['required', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        abort_unless(trim($input['name_ar']) !== '' && trim($input['name_en']) !== '', 422);

        $sellerId = (int) $input['seller_id'];
        $authorized = DB::table('sellers')->where('id', $sellerId)->where('status', 'approved')
            ->whereExists(function ($q) use ($request): void {
                $q->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'sellers.id')
                    ->where('seller_staff_access.user_id', $request->user()->id)
                    ->where('seller_staff_access.is_active', true)
                    ->where('seller_staff_access.role', 'owner')
                    ->whereNull('seller_staff_access.branch_id');
            })->exists();
        abort_unless($authorized, 404);

        $zone = ServiceZone::query()->active()->findOrFail($input['service_zone_id']);
        abort_unless($zone->distanceTo((float) $input['lat'], (float) $input['lng']) <= $zone->radius_meters,
            422, 'Branch location is outside the selected delivery zone.');

        $branch = Branch::query()->create([
            'seller_id' => $sellerId,
            'service_zone_id' => $zone->id,
            'code' => strtoupper($input['code']),
            'name_ar' => trim($input['name_ar']),
            'name_en' => trim($input['name_en']),
            'lat' => $input['lat'],
            'lng' => $input['lng'],
            'is_active' => false,
        ]);

        return response()->json(['data' => [
            'id' => $branch->id,
            'seller_id' => $branch->seller_id,
            'code' => $branch->code,
            'is_active' => false,
            'review_required' => true,
        ]], 201);
    }
}
