<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Models\SellerApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AdminSellerApplicationController extends Controller
{
    public function index(Request $request)
    {
        $applications =
            SellerApplication::query()
                ->with([
                    'user:id,name,email,role,is_active',
                    'seller:id,name_ar,name_en,status',
                ])

                ->when(
                    $request->filled('status'),
                    fn ($query) =>
                        $query->where(
                            'status',
                            $request->status
                        )
                )

                ->latest('id')
                ->paginate(30);

        return response()->json(
            $applications
        );
    }

    public function show(
        SellerApplication $sellerApplication
    ) {
        $sellerApplication->load([
            'user:id,name,email,role,is_active',
            'seller',
            'reviewer:id,name,email',
        ]);

        return response()->json([
            'data' =>
                $sellerApplication,
        ]);
    }

    public function review(
        Request $request,
        SellerApplication $sellerApplication
    ) {
        $validated = $request->validate([
            'status' => [
                'required',
                Rule::in([
                    'approved',
                    'rejected',
                ]),
            ],

            'admin_note' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'commission_percentage' => [
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],
        ]);

        $application = DB::transaction(
            function () use (
                $request,
                $sellerApplication,
                $validated
            ) {
                $application =
                    SellerApplication::query()
                        ->where(
                            'id',
                            $sellerApplication->id
                        )
                        ->lockForUpdate()
                        ->firstOrFail();

                if ($application->status !== 'pending') {
                    abort(
                        422,
                        'This application has already been reviewed.'
                    );
                }

                $seller = null;

                if (
                    $validated['status']
                    === 'approved'
                ) {
                    $baseSlug = Str::slug(
                        $application->store_name_en
                    );

                    if (!$baseSlug) {
                        $baseSlug = 'seller';
                    }

                    $slug = $baseSlug;
                    $counter = 1;

                    while (
                        Seller::where(
                            'slug',
                            $slug
                        )->exists()
                    ) {
                        $slug =
                            $baseSlug .
                            '-' .
                            $counter;

                        $counter++;
                    }

                    $seller = Seller::create([
                        'user_id' =>
                            $application->user_id,

                        'name_ar' =>
                            $application->store_name_ar,

                        'name_en' =>
                            $application->store_name_en,

                        'slug' =>
                            $slug,

                        'phone' =>
                            $application->phone,

                        'email' =>
                            $application->email,

                        'description_ar' =>
                            $application
                                ->description_ar,

                        'description_en' =>
                            $application
                                ->description_en,

                        'status' =>
                            'approved',

                        'commission_percentage' =>
                            $validated[
                                'commission_percentage'
                            ] ?? 10,

                        'is_active' =>
                            true,
                    ]);

                    $user =
                        $application->user()
                            ->lockForUpdate()
                            ->first();

                    // Never demote an admin.
                    if (
                        $user &&
                        $user->role !== 'admin'
                    ) {
                        $user->update([
                            'role' => 'seller',
                        ]);
                    }
                }

                $application->update([
                    'seller_id' =>
                        $seller?->id,

                    'status' =>
                        $validated['status'],

                    'admin_note' =>
                        $validated['admin_note']
                        ?? null,

                    'reviewed_by' =>
                        $request->user()->id,

                    'reviewed_at' =>
                        now(),
                ]);

                return $application;
            }
        );

        return response()->json([
            'message' =>
                'Seller application reviewed successfully.',

            'data' =>
                $application->fresh([
                    'user',
                    'seller',
                    'reviewer',
                ]),
        ]);
    }
}
