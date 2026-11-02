<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerApplicationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $application = DB::table('seller_applications')->where('user_id', $request->user()->id)->first();

        return response()->json(['data' => $application]);
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
        ]);

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

        return response()->json(['data' => $application]);
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

        return response()->json(['data' => $application]);
    }
}
