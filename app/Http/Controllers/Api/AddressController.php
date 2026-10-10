<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    public function index(Request $request)
    {
        $addresses = $request->user()
            ->addresses()
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $addresses,
        ]);
    }

    public function show(
        Request $request,
        Address $address
    ) {
        $this->ensureOwnership(
            $request,
            $address
        );

        return response()->json([
            'data' => $address,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'phone' => [
                'required',
                'string',
                'max:50',
            ],

            'city' => [
                'required',
                'string',
                'max:255',
            ],

            'district' => [
                'nullable',
                'string',
                'max:255',
            ],

            'street' => [
                'nullable',
                'string',
                'max:255',
            ],

            'building' => [
                'nullable',
                'string',
                'max:255',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'is_default' => [
                'nullable',
                'boolean',
            ],
        ]);

        $user = $request->user();

        $address = DB::transaction(function () use (
            $user,
            $validated
        ) {
            $hasAddress = $user
                ->addresses()
                ->exists();

            $makeDefault =
                ($validated['is_default'] ?? false)
                || !$hasAddress;

            if ($makeDefault) {
                $user
                    ->addresses()
                    ->update([
                        'is_default' => false,
                    ]);
            }

            return $user
                ->addresses()
                ->create([
                    'name' =>
                        $validated['name'],

                    'phone' =>
                        $validated['phone'],

                    'city' =>
                        $validated['city'],

                    'district' =>
                        $validated['district'] ?? null,

                    'street' =>
                        $validated['street'] ?? null,

                    'building' =>
                        $validated['building'] ?? null,

                    'notes' =>
                        $validated['notes'] ?? null,

                    'is_default' =>
                        $makeDefault,
                ]);
        });

        return response()->json([
            'message' =>
                'Address created successfully.',

            'data' => $address,
        ], 201);
    }

    public function update(
        Request $request,
        Address $address
    ) {
        $this->ensureOwnership(
            $request,
            $address
        );

        $validated = $request->validate([
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'phone' => [
                'sometimes',
                'required',
                'string',
                'max:50',
            ],

            'city' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'district' => [
                'nullable',
                'string',
                'max:255',
            ],

            'street' => [
                'nullable',
                'string',
                'max:255',
            ],

            'building' => [
                'nullable',
                'string',
                'max:255',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'is_default' => [
                'nullable',
                'boolean',
            ],
        ]);

        DB::transaction(function () use (
            $request,
            $address,
            $validated
        ) {
            if (
                array_key_exists(
                    'is_default',
                    $validated
                )
                && $validated['is_default']
            ) {
                $request->user()
                    ->addresses()
                    ->where(
                        'id',
                        '!=',
                        $address->id
                    )
                    ->update([
                        'is_default' => false,
                    ]);
            }

            $address->update($validated);
        });

        return response()->json([
            'message' =>
                'Address updated successfully.',

            'data' => $address->fresh(),
        ]);
    }

    public function setDefault(
        Request $request,
        Address $address
    ) {
        $this->ensureOwnership(
            $request,
            $address
        );

        DB::transaction(function () use (
            $request,
            $address
        ) {
            $request->user()
                ->addresses()
                ->update([
                    'is_default' => false,
                ]);

            $address->update([
                'is_default' => true,
            ]);
        });

        return response()->json([
            'message' =>
                'Default address updated successfully.',

            'data' => $address->fresh(),
        ]);
    }

    public function destroy(
        Request $request,
        Address $address
    ) {
        $this->ensureOwnership(
            $request,
            $address
        );

        DB::transaction(function () use (
            $request,
            $address
        ) {
            $wasDefault =
                $address->is_default;

            $address->delete();

            if ($wasDefault) {
                $nextAddress = $request->user()
                    ->addresses()
                    ->latest('id')
                    ->first();

                if ($nextAddress) {
                    $nextAddress->update([
                        'is_default' => true,
                    ]);
                }
            }
        });

        return response()->json([
            'message' =>
                'Address deleted successfully.',
        ]);
    }

    private function ensureOwnership(
        Request $request,
        Address $address
    ): void {
        abort_unless(
            $address->user_id ===
                $request->user()->id,
            404
        );
    }
}
