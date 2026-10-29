<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\CustomerRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CustomerRequestRequest;
use App\Http\Resources\CustomerRequestResource;
use App\Support\Exceptions\DomainRuleViolation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerRequestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CustomerRequestResource::collection(CustomerRequest::query()->where('user_id', $request->user()->id)->latest('id')->paginate(20));
    }

    public function store(CustomerRequestRequest $request): CustomerRequestResource
    {
        $fields = $request->validated();
        $record = CustomerRequest::query()->firstOrCreate([
            'user_id' => $request->user()->id, 'request_key' => $fields['request_key'],
        ], $fields);
        if ($record->kind !== $fields['kind'] || $record->subject !== $fields['subject'] || $record->description !== $fields['description']) {
            throw new DomainRuleViolation('request_key_reused', 'Use a new reference for a different request.', 409);
        }

        return new CustomerRequestResource($record);
    }
}
