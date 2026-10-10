<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SellerController extends Controller
{
    // Public
    public function index()
    {
        $sellers = Seller::query()
            ->where('status', 'approved')
            ->where('is_active', true)
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'data' => $sellers,
        ]);
    }

    // Public
    public function show(Seller $seller)
    {
        if (
            $seller->status !== 'approved' ||
            !$seller->is_active
        ) {
            return response()->json([
                'message' => 'Seller not found.',
            ], 404);
        }

        $seller->load([
            'products' => function ($query) {
                $query
                    ->where('is_active', true)
                    ->orderBy('id', 'desc');
            },
        ]);

        return response()->json([
            'data' => $seller,
        ]);
    }

    // Admin
    public function adminIndex()
    {
        $sellers = Seller::query()
            ->with('user:id,name,email,role,is_active')
            ->withCount('products')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'data' => $sellers,
        ]);
    }

    // Admin
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                'unique:sellers,user_id',
            ],

            'name_ar' => [
                'required',
                'string',
                'max:255',
            ],

            'name_en' => [
                'required',
                'string',
                'max:255',
            ],

            'slug' => [
                'nullable',
                'string',
                'max:255',
                'unique:sellers,slug',
            ],

            'logo' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'phone' => [
                'nullable',
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
            ],

            'description_en' => [
                'nullable',
                'string',
            ],

            'status' => [
                'nullable',
                Rule::in([
                    'pending',
                    'approved',
                    'rejected',
                    'suspended',
                ]),
            ],

            'commission_percentage' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        $seller = Seller::create([
            'user_id' => $validated['user_id'] ?? null,

            'name_ar' => $validated['name_ar'],
            'name_en' => $validated['name_en'],

            'slug' => $validated['slug']
                ?? Str::slug($validated['name_en']),

            'logo' => $validated['logo'] ?? null,

            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,

            'description_ar' =>
                $validated['description_ar'] ?? null,

            'description_en' =>
                $validated['description_en'] ?? null,

            'status' =>
                $validated['status'] ?? 'pending',

            'commission_percentage' =>
                $validated['commission_percentage'] ?? 0,

            'is_active' =>
                $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Seller created successfully.',
            'data' => $seller,
        ], 201);
    }

    // Admin
    public function update(
        Request $request,
        Seller $seller
    ) {
        $validated = $request->validate([
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',

                Rule::unique('sellers', 'user_id')
                    ->ignore($seller->id),
            ],

            'name_ar' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'name_en' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],

            'slug' => [
                'nullable',
                'string',
                'max:255',

                Rule::unique('sellers', 'slug')
                    ->ignore($seller->id),
            ],

            'logo' => [
                'nullable',
                'string',
                'max:2048',
            ],

            'phone' => [
                'nullable',
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
            ],

            'description_en' => [
                'nullable',
                'string',
            ],

            'status' => [
                'nullable',
                Rule::in([
                    'pending',
                    'approved',
                    'rejected',
                    'suspended',
                ]),
            ],

            'commission_percentage' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],
        ]);

        if (
            isset($validated['name_en']) &&
            !array_key_exists('slug', $validated)
        ) {
            $validated['slug'] =
                Str::slug($validated['name_en']);
        }

        $seller->update($validated);

        return response()->json([
            'message' => 'Seller updated successfully.',
            'data' => $seller->fresh(),
        ]);
    }

    // Admin
    public function updateStatus(
        Request $request,
        Seller $seller
    ) {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'pending',
                    'approved',
                    'rejected',
                    'suspended',
                ]),
            ],
        ]);

        $seller->update([
            'status' => $validated['status'],
        ]);

        // If seller is linked to a user,
        // update that user's role.
        if (
            $seller->user &&
            $validated['status'] === 'approved'
        ) {
            $seller->user->update([
                'role' => 'seller',
            ]);
        }

        return response()->json([
            'message' => 'Seller status updated successfully.',
            'data' => $seller->fresh(),
        ]);
    }

    // Admin
    public function destroy(Seller $seller)
    {
        if ($seller->products()->exists()) {
            return response()->json([
                'message' =>
                    'Cannot delete a seller that has products.',
            ], 422);
        }

        $seller->delete();

        return response()->json([
            'message' => 'Seller deleted successfully.',
        ]);
    }
}
