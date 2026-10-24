<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Addressing\Models\Address;
use App\Domain\Addressing\Services\AddressBook;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AddressRequest;
use App\Http\Resources\AddressResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AddressController extends Controller
{
    public function __construct(private readonly AddressBook $addresses) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection($request->user()->addresses()->orderByDesc('is_default')->latest('id')->get());
    }

    public function store(AddressRequest $request): JsonResponse
    {
        $address = $this->addresses->add($request->user(), $request->addressData());

        return (new AddressResource($address))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $address): AddressResource
    {
        return new AddressResource($this->owned($request, $address));
    }

    public function update(AddressRequest $request, int $address): AddressResource
    {
        return new AddressResource($this->addresses->update($this->owned($request, $address), $request->addressData()));
    }

    public function makeDefault(Request $request, int $address): AddressResource
    {
        $owned = $this->owned($request, $address);
        $this->addresses->makeDefault($owned);

        return new AddressResource($owned->refresh());
    }

    public function destroy(Request $request, int $address): Response
    {
        $this->addresses->remove($this->owned($request, $address));

        return response()->noContent();
    }

    /** Scoped to the signed-in user, so someone else's address id is simply "not found". */
    private function owned(Request $request, int $id): Address
    {
        return $request->user()->addresses()->findOrFail($id);
    }
}
