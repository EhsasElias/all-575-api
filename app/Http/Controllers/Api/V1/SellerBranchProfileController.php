<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\ServiceZone;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Only an approved store owner may manage their own branch profile.
 * Zone changes stay inside the zone boundary and historical branches cannot be deleted.
 */
final class SellerBranchProfileController extends Controller
{
    private function ownerBranch(Request $request, int $branch): object
    {
        return DB::table('branches')
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
            ->select('branches.*')
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function update(Request $request, int $branch): JsonResponse
    {
        // Older app versions send the two names only. Keep those requests working.
        if ($request->exists('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }

        $data = $request->validate([
            'code' => ['sometimes', 'required', 'string', 'min:2', 'max:32',
                'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('branches', 'code')->ignore($branch)],
            'name_ar' => ['required', 'string', 'max:180'],
            'name_en' => ['required', 'string', 'max:180'],
            'service_zone_id' => ['sometimes', 'required', 'integer',
                Rule::exists('service_zones', 'id')->where('is_active', true)],
            'lat' => ['sometimes', 'required', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'required', 'numeric', 'between:-180,180'],
        ]);
        if (trim($data['name_ar']) === '' || trim($data['name_en']) === '') {
            throw ValidationException::withMessages([
                'name_ar' => ['أدخل اسم الفرع بالعربية والإنجليزية.'],
            ]);
        }

        $updated = DB::transaction(function () use ($request, $branch, $data): array {
            $row = $this->ownerBranch($request, $branch);
            $code = $data['code'] ?? $row->code;
            $zoneId = (int) ($data['service_zone_id'] ?? $row->service_zone_id);
            $lat = (float) ($data['lat'] ?? $row->lat);
            $lng = (float) ($data['lng'] ?? $row->lng);

            // Always validate the final combined coordinates/zone, including partial changes.
            $zone = ServiceZone::query()->active()->findOrFail($zoneId);
            if ($zone->distanceTo($lat, $lng) > $zone->radius_meters) {
                throw ValidationException::withMessages([
                    'lat' => ['موقع الفرع خارج منطقة التوصيل. حدد موقعاً داخل المنطقة.'],
                ]);
            }

            DB::table('branches')->where('id', $row->id)->update([
                'code' => $code,
                'name_ar' => trim($data['name_ar']),
                'name_en' => trim($data['name_en']),
                'service_zone_id' => $zoneId,
                'lat' => $lat,
                'lng' => $lng,
                'updated_at' => now(),
            ]);

            return [
                'id' => $row->id,
                'seller_id' => $row->seller_id,
                'code' => $code,
                'name_ar' => trim($data['name_ar']),
                'name_en' => trim($data['name_en']),
                'service_zone_id' => $zoneId,
                'lat' => $lat,
                'lng' => $lng,
                'is_active' => (bool) $row->is_active,
            ];
        }, 3);

        return response()->json(['data' => $updated]);
    }

    public function destroy(Request $request, int $branch): JsonResponse
    {
        try {
            DB::transaction(function () use ($request, $branch): void {
                $row = $this->ownerBranch($request, $branch);

                // Do not destroy stock, order history or access assignments.
                foreach (['branch_stock', 'suborders', 'seller_staff_access'] as $table) {
                    if (DB::table($table)->where('branch_id', $row->id)->exists()) {
                        abort(409, 'لا يمكن حذف فرع مرتبط بمخزون أو طلبات أو موظفين.');
                    }
                }

                // A branch proposal belongs solely to this branch.
                DB::table('seller_branch_proposals')->where('branch_id', $row->id)->delete();
                DB::table('branches')->where('id', $row->id)->delete();
            }, 3);
        } catch (QueryException $exception) {
            // Additional database foreign keys also prevent deletion and preserve data.
            if (in_array((string) $exception->getCode(), ['23000', '23503'], true)) {
                abort(409, 'لا يمكن حذف فرع مرتبط بسجلات أخرى.');
            }
            throw $exception;
        }

        return response()->json(['data' => ['deleted' => true]]);
    }
}
