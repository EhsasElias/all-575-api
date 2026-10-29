<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\CustomerBillingProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateBillingProfileRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingProfileController extends Controller
{
    /** @response array{data: array{buyer_type: string, company_name: ?string, registration_number: ?string, vat_number: ?string, billing_address: ?string}} */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->details($request->user()->id)]);
    }

    /** @response array{data: array{buyer_type: string, company_name: ?string, registration_number: ?string, vat_number: ?string, billing_address: ?string}} */
    public function update(UpdateBillingProfileRequest $request): JsonResponse
    {
        $fields = $request->validated();
        if ($fields['buyer_type'] === 'individual') {
            $fields = ['buyer_type' => 'individual', 'company_name' => null, 'registration_number' => null, 'vat_number' => null, 'billing_address' => null];
        }
        CustomerBillingProfile::query()->updateOrCreate(['user_id' => $request->user()->id], $fields);

        return response()->json(['data' => $this->details($request->user()->id)]);
    }

    public function details(int $userId): array
    {
        return CustomerBillingProfile::query()->where('user_id', $userId)->first()?->only([
            'buyer_type', 'company_name', 'registration_number', 'vat_number', 'billing_address',
        ]) ?? ['buyer_type' => 'individual', 'company_name' => null, 'registration_number' => null, 'vat_number' => null, 'billing_address' => null];
    }
}
