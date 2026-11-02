<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\Suborder;
use App\Domain\Orders\Services\SuborderTransitions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SellerWorkspaceController extends Controller
{
    /** Seller membership and branch restrictions are enforced in every query. */
    private function scopedOrders(Request $request): Builder
    {
        $userId = $request->user()->id;

        return Suborder::query()->whereHas('seller', fn ($seller) => $seller->approved())->whereIn('seller_id', function ($query) use ($userId): void {
            $query->select('seller_id')->from('seller_staff_access')
                ->where('user_id', $userId)->where('is_active', true)
                ->whereIn('role', ['owner', 'branch_manager', 'picker']);
        })->where(function ($query) use ($userId): void {
            $query->whereIn('branch_id', function ($memberships) use ($userId): void {
                $memberships->select('branch_id')->from('seller_staff_access')
                    ->where('user_id', $userId)->where('is_active', true)
                    ->whereIn('role', ['owner', 'branch_manager', 'picker'])
                    ->whereNotNull('branch_id')->whereColumn('seller_id', 'suborders.seller_id');
            })->orWhereExists(function ($memberships) use ($userId): void {
                $memberships->selectRaw('1')->from('seller_staff_access')
                    ->where('user_id', $userId)->where('is_active', true)
                    ->whereIn('role', ['owner', 'branch_manager', 'picker'])
                    ->whereNull('branch_id')->whereColumn('seller_id', 'suborders.seller_id');
            });
        });
    }

    public function dashboard(Request $request): JsonResponse
    {
        $base = $this->scopedOrders($request);
        $counts = (clone $base)->selectRaw("status, COUNT(*) as total")
            ->groupBy('status')->pluck('total', 'status');

        return response()->json(['data' => [
            'awaiting_seller' => (int) ($counts['awaiting_seller'] ?? 0),
            'preparing' => (int) ($counts['preparing'] ?? 0),
            'ready' => (int) ($counts['ready'] ?? 0),
            'on_the_way' => (int) ($counts['on_the_way'] ?? 0),
            'delivered' => (int) ($counts['delivered'] ?? 0),
        ]]);
    }

    public function orders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'before_id' => ['sometimes', 'ulid'],
        ]);

        $orders = $this->scopedOrders($request)
            ->whereHas('order', fn ($query) => $query->whereIn('status', ['confirmed', 'completed']))
            ->with(['items', 'branch', 'order:id,number,status,placed_at,slot_starts_at'])
            ->when(isset($validated['before_id']), fn (Builder $query) => $query->where('id', '<', $validated['before_id']))
            ->orderByDesc('id')->limit($validated['limit'] ?? 20)->get();

        return response()->json(['data' => $orders->map(fn (Suborder $suborder): array => [
            'id' => $suborder->id,
            'order_number' => $suborder->order->number,
            'status' => $suborder->status->value,
            'branch_id' => $suborder->branch_id,
            'placed_at' => $suborder->order->placed_at,
            'delivery_slot' => $suborder->order->slot_starts_at,
            'items' => $suborder->items->map(fn ($item): array => [
                'id' => $item->id,
                'name' => ['ar' => $item->name_ar, 'en' => $item->name_en],
                'quantity' => $item->quantity,
            ])->all(),
        ])->all()]);
    }

    public function advance(Request $request, string $suborder, SuborderTransitions $transitions): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['preparing', 'ready'])],
        ]);

        $order = $this->scopedOrders($request)->whereKey($suborder)->firstOrFail();
        $hasPermission = DB::table('seller_staff_access')
            ->where('user_id', $request->user()->id)->where('seller_id', $order->seller_id)
            ->where('is_active', true)->whereIn('role', ['owner', 'branch_manager', 'picker'])
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $order->branch_id))
            ->exists();
        abort_unless($hasPermission, 403);

        $updated = $transitions->move($order, SuborderStatus::from($validated['status']), 'seller');

        return response()->json(['data' => ['id' => $updated->id, 'status' => $updated->status->value]]);
    }
}
