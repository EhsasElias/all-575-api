<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Models\ServiceZone;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResolveZoneRequest;
use App\Http\Resources\ZoneResource;
use Illuminate\Http\JsonResponse;

class ZoneController extends Controller
{
    /**
     * Tells the app whether we deliver to a point, and which zone it belongs to.
     *
     * @response array{data: array{serviceable: bool, zone: ?ZoneResource}}
     */
    public function resolve(ResolveZoneRequest $request): JsonResponse
    {
        $zone = ServiceZone::resolve((float) $request->input('lat'), (float) $request->input('lng'));

        return response()->json(['data' => [
            'serviceable' => $zone !== null,
            'zone' => $zone === null ? null : new ZoneResource($zone),
        ]]);
    }
}
