<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Seller-facing return queue. Decisions/refunds remain with authorized
 * aftercare staff until a separate audited dispute workflow is implemented.
 */
final class SellerReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'seller_id' => ['sometimes', 'integer', 'min:1'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['submitted', 'in_review', 'resolved', 'rejected'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'before_id' => ['sometimes', 'ulid'],
        ]);

        $cases = $this->visibleCases($request)
            ->when(isset($filters['seller_id']), fn ($query) => $query->where('suborders.seller_id', $filters['seller_id']))
            ->when(isset($filters['branch_id']), fn ($query) => $query->where('suborders.branch_id', $filters['branch_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('customer_cases.status', $filters['status']))
            ->when(isset($filters['before_id']), fn ($query) => $query->where('customer_cases.id', '<', $filters['before_id']))
            ->select(
                'customer_cases.id',
                'customer_cases.order_id',
                'customer_cases.order_item_id',
                'customer_cases.status',
                'customer_cases.subject',
                'customer_cases.quantity',
                'customer_cases.created_at',
                'suborders.seller_id',
                'suborders.branch_id',
                'order_items.name_ar',
                'order_items.name_en'
            )
            ->orderByDesc('customer_cases.id')
            ->limit($filters['limit'] ?? 20)
            ->get();

        return response()->json(['data' => $cases]);
    }

    public function show(Request $request, string $case): JsonResponse
    {
        $result = $this->visibleCases($request)
            ->where('customer_cases.id', $case)
            ->select(
                'customer_cases.id',
                'customer_cases.order_id',
                'customer_cases.order_item_id',
                'customer_cases.status',
                'customer_cases.subject',
                'customer_cases.quantity',
                'customer_cases.created_at',
                'customer_cases.updated_at',
                'suborders.seller_id',
                'suborders.branch_id',
                'order_items.name_ar',
                'order_items.name_en'
            )
            ->firstOrFail();

        return response()->json(['data' => $result]);
    }

    public function respond(Request $request, string $case): JsonResponse
    {
        $input = $request->validate([
            'position' => ['required', Rule::in(['accept', 'dispute'])],
            'explanation' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $result = DB::transaction(function () use ($request, $case, $input): array {
            $visible = $this->visibleCases($request)
                ->where('customer_cases.id', $case)
                ->whereIn('customer_cases.status', ['submitted', 'in_review'])
                ->select('customer_cases.id', 'customer_cases.status', 'suborders.seller_id')
                ->firstOrFail();

            $locked = DB::table('customer_cases')->where('id', $visible->id)
                ->lockForUpdate()->first();
            abort_if($locked === null || ! in_array($locked->status, ['submitted', 'in_review'], true),
                409, 'The return is no longer open for seller responses.');

            // One recorded position per seller/case; no overwrites or hidden revisions.
            abort_if(DB::table('seller_case_responses')
                ->where('customer_case_id', $case)
                ->where('seller_id', $visible->seller_id)
                ->exists(), 409, 'A seller response already exists.');

            $id = DB::table('seller_case_responses')->insertGetId([
                'customer_case_id' => $case,
                'seller_id' => $visible->seller_id,
                'user_id' => $request->user()->id,
                'position' => $input['position'],
                'explanation' => $input['explanation'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['id' => $id, 'case_id' => $case, 'position' => $input['position']];
        }, 3);

        return response()->json(['data' => $result], 201);
    }

    private function visibleCases(Request $request): \Illuminate\Database\Query\Builder
    {
        return DB::table('customer_cases')
            ->join('order_items', 'order_items.id', '=', 'customer_cases.order_item_id')
            ->join('suborders', 'suborders.id', '=', 'order_items.suborder_id')
            ->join('sellers', 'sellers.id', '=', 'suborders.seller_id')
            ->whereColumn('customer_cases.order_id', 'suborders.order_id')
            ->where('customer_cases.kind', 'return')
            ->where('sellers.status', 'approved')
            ->whereExists(function ($query) use ($request): void {
                $query->selectRaw('1')
                    ->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'suborders.seller_id')
                    ->where('seller_staff_access.user_id', $request->user()->id)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager'])
                    ->where(function ($scope): void {
                        $scope->whereNull('seller_staff_access.branch_id')
                            ->orWhereColumn('seller_staff_access.branch_id', 'suborders.branch_id');
                    });
            });
    }
}
