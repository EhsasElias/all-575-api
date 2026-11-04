<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;

class SellerApplicationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $application = DB::table('seller_applications')->where('user_id', $request->user()->id)->first();

        return response()->json(['data' => $this->displayApplication($application)]);
    }

    public function save(Request $request): JsonResponse
    {
        abort_unless($request->user()->isVerified(), 403);

        $details = $request->validate([
            'legal_name' => ['required', 'string', 'max:180'],
            'commercial_registration' => ['required', 'string', 'max:30'],
            'national_unified_number' => ['nullable', 'string', 'max:20'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_email' => ['required', 'email', 'max:180'],
            'contact_phone' => ['required', 'string', 'max:20'],
            'city' => ['required', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'commercial_registration_status' => ['nullable', 'string', 'max:100'],
            'commercial_registration_issue_date' => ['nullable', 'date_format:Y-m-d'],
            'commercial_registration_expiry_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:commercial_registration_issue_date'],
            'chamber_of_commerce_number' => ['nullable', 'string', 'max:100'],
            'building_number' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:100'],
            'additional_number' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:180'],
            'district' => ['nullable', 'string', 'max:100'],
            'iban' => ['nullable', 'string', 'max:100'],
        ]);

        if (isset($details['iban']) && trim($details['iban']) !== '') {
            $details['iban'] = Crypt::encryptString(strtoupper(trim($details['iban'])));
        }

        $userId = $request->user()->id;
        $application = DB::transaction(function () use ($userId, $details) {
            $existing = DB::table('seller_applications')->where('user_id', $userId)->lockForUpdate()->first();
            abort_if($existing !== null && ! in_array($existing->status, ['draft', 'correction'], true), 409, 'Submitted applications cannot be edited.');

            if ($existing === null) {
                DB::table('seller_applications')->insert($details + [
                    'user_id' => $userId, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('seller_applications')->where('id', $existing->id)->update($details + ['status' => 'draft', 'review_note' => null, 'updated_at' => now()]);
            }

            return DB::table('seller_applications')->where('user_id', $userId)->first();
        });

        return response()->json(['data' => $this->displayApplication($application)]);
    }

    private function displayApplication(?object $application): ?object
    {
        if ($application === null) {
            return null;
        }
        if (isset($application->iban) && $application->iban !== '') {
            try {
                $application->iban = Crypt::decryptString($application->iban);
            } catch (DecryptException) {
                // Legacy plaintext records may exist in test environments.
                // Never echo ciphertext back to users if a stored value is unreadable.
                $application->iban = null;
            }
        }

        return $application;
    }

    public function submit(Request $request): JsonResponse
    {
        abort_unless($request->user()->isVerified(), 403);

        $application = DB::transaction(function () use ($request) {
            $query = DB::table('seller_applications')->where('user_id', $request->user()->id);
            $application = (clone $query)->lockForUpdate()->first();
            abort_if($application === null, 404, 'Create an application first.');
            abort_if($application->status !== 'draft', 409, 'Application already submitted.');

            $query->update(['status' => 'submitted', 'submitted_at' => now(), 'updated_at' => now()]);

            return $query->first();
        });

        return response()->json(['data' => $this->displayApplication($application)]);
    }
}
