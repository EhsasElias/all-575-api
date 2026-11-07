<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\ServiceZone;
use App\Http\Controllers\Controller;
use App\Support\SellerDemoMode;
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

    /**
     * Demo-only zone creation. Zones affect public delivery availability, so ordinary
     * sellers cannot create global service zones without an administrator.
     */
    public function createServiceZone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'name_ar' => ['required', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
            'center_lat' => ['required', 'numeric', 'between:-90,90'],
            'center_lng' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'between:100,50000'],
        ]);
        $sellerId = (int) $data['seller_id'];
        abort_unless(SellerDemoMode::allows($request, $sellerId), 403,
            'إنشاء مناطق جديدة متاح لحساب العرض التجريبي فقط.');
        $authorized = DB::table('seller_staff_access')
            ->where('seller_id', $sellerId)
            ->where('user_id', $request->user()->id)
            ->where('role', 'owner')
            ->whereNull('branch_id')
            ->where('is_active', true)
            ->exists();
        abort_unless($authorized, 403);
        // Keep delivery pricing consistent with the existing geographic zone.
        // A demo seller cannot override fees for customers by creating a new zone.
        $parentZone = ServiceZone::resolve((float) $data['center_lat'], (float) $data['center_lng']);
        if ($parentZone === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'center_lat' => ['حدد مركز المنطقة ضمن نطاق توصيل قائم.'],
            ]);
        }
        $zone = ServiceZone::query()->create([
            'name_ar' => trim($data['name_ar']),
            'name_en' => trim($data['name_en']),
            'center_lat' => (float) $data['center_lat'],
            'center_lng' => (float) $data['center_lng'],
            'radius_meters' => (int) $data['radius_meters'],
            'delivery_fee_halalas' => $parentZone->delivery_fee_halalas,
            'free_delivery_threshold_halalas' => $parentZone->free_delivery_threshold_halalas,
            'is_active' => true,
        ]);
        return response()->json(['data' => $zone->only([
            'id', 'name_ar', 'name_en', 'center_lat', 'center_lng', 'radius_meters',
        ])], 201);
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
            'service_zone_ids' => ['sometimes', 'array', 'min:1', 'max:30'],
            'service_zone_ids.*' => ['required', 'integer', 'distinct',
                Rule::exists('service_zones', 'id')->where('is_active', true)],
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

        $coverageZoneIds = array_map('intval', $input['service_zone_ids'] ?? [$zone->id]);
        if (! in_array($zone->id, $coverageZoneIds, true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'service_zone_ids' => ['يجب تضمين المنطقة الأساسية ضمن مناطق تغطية الفرع.'],
            ]);
        }

        $demoDirect = SellerDemoMode::allows($request, $sellerId);

        $branch = DB::transaction(function () use ($input, $sellerId, $zone, $request, $demoDirect, $coverageZoneIds): Branch {
            $branch = Branch::query()->create([
                'seller_id' => $sellerId,
                'service_zone_id' => $zone->id,
                'code' => strtoupper($input['code']),
                'name_ar' => trim($input['name_ar']),
                'name_en' => trim($input['name_en']),
                'lat' => $input['lat'],
                'lng' => $input['lng'],
                'is_active' => $demoDirect,
            ]);
            DB::table('branch_service_zones')->insert(
                array_map(static fn (int $zoneId): array => [
                    'branch_id' => $branch->id,
                    'service_zone_id' => $zoneId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $coverageZoneIds)
            );
            if (! $demoDirect) {
                DB::table('seller_branch_proposals')->insert([
                    'branch_id' => $branch->id,
                    'requested_by' => $request->user()->id,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $branch;
        }, 3);

        return response()->json(['data' => [
            'id' => $branch->id,
            'seller_id' => $branch->seller_id,
            'code' => $branch->code,
            'is_active' => $demoDirect,
            'service_zone_id' => $zone->id,
            'service_zone_ids' => $coverageZoneIds,
            'review_required' => ! $demoDirect,
        ]], 201);
    }
}
