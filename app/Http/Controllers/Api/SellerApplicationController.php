<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SellerApplication;
use Illuminate\Http\Request;

class SellerApplicationController extends Controller
{
    public function index(Request $request)
    {
        $applications = SellerApplication::query()
            ->where(
                'user_id',
                $request->user()->id
            )
            ->latest('id')
            ->get();

        return response()->json([
            'data' => $applications,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'store_name_ar' => [
                'required',
                'string',
                'max:255',
            ],

            'store_name_en' => [
                'required',
                'string',
                'max:255',
            ],

            'phone' => [
                'required',
                'string',
                'max:50',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'description_ar' => [
                'nullable',
                'string',
                'max:3000',
            ],

            'description_en' => [
                'nullable',
                'string',
                'max:3000',
            ],
        ]);

        $user = $request->user();

        if ($user->seller) {
            return response()->json([
                'message' =>
                    'You already have a seller account.',
            ], 422);
        }

        $hasPending = SellerApplication::query()
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return response()->json([
                'message' =>
                    'You already have a pending seller application.',
            ], 422);
        }

        $application = SellerApplication::create([
            'user_id' =>
                $user->id,

            'store_name_ar' =>
                $validated['store_name_ar'],

            'store_name_en' =>
                $validated['store_name_en'],

            'phone' =>
                $validated['phone'],

            'email' =>
                $validated['email']
                ?? $user->email,

            'description_ar' =>
                $validated['description_ar']
                ?? null,

            'description_en' =>
                $validated['description_en']
                ?? null,

            'status' =>
                'pending',
        ]);

        return response()->json([
            'message' =>
                'Seller application submitted successfully.',

            'data' =>
                $application,
        ], 201);
    }

    public function show(
        Request $request,
        SellerApplication $sellerApplication
    ) {
        abort_unless(
            $sellerApplication->user_id ===
                $request->user()->id,
            404
        );

        return response()->json([
            'data' => $sellerApplication,
        ]);
    }
}
