<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orders\Services\DeliverySlots;
use App\Http\Controllers\Controller;
use App\Http\Resources\SlotResource;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SlotController extends Controller
{
    public function index(Request $request, DeliverySlots $slots): AnonymousResourceCollection
    {
        $request->validate(['address_id' => ['required', 'integer']]);

        $address = $request->user()->addresses()->findOrFail($request->integer('address_id'));

        if (! $address->isServiceable()) {
            throw new DomainRuleViolation('address_not_serviceable', 'We do not deliver to this address yet.');
        }

        return SlotResource::collection($slots->bookable($address->service_zone_id));
    }
}
