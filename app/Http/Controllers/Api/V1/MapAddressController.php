<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Addressing\ReverseGeocoder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResolveZoneRequest;
use App\Http\Resources\MapAddressResource;

class MapAddressController extends Controller
{
    /** Suggest available address fields for a customer-selected map pin. */
    public function __invoke(ResolveZoneRequest $request, ReverseGeocoder $geocoder): MapAddressResource
    {
        return new MapAddressResource($geocoder->lookup(
            (float) $request->input('lat'), (float) $request->input('lng'), app()->getLocale(),
        ));
    }
}
