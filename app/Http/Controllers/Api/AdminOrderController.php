<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Notifications\OrderStatusChangedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminOrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
            ->with([
                'user:id,name,email',
            ])
            ->withCount('items')

            ->when(
                $request->filled('status'),
                fn ($query) => $query->where(
                    'status',
                    $request->status
                )
            )

            ->when(
                $request->filled('payment_status'),
                fn ($query) => $query->where(
                    'payment_status',
                    $request->payment_status
                )
            )

            ->when(
                $request->filled('search'),
                function ($query) use ($request) {
                    $search =
                        trim($request->search);

                    $query->where(
                        function ($q) use ($search) {
                            $q
                                ->where(
                                    'order_number',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'customer_name',
                                    'like',
                                    "%{$search}%"
                                )
                                ->orWhere(
                                    'customer_phone',
                                    'like',
                                    "%{$search}%"
                                );
                        }
                    );
                }
            )

            ->latest('id')
            ->paginate(30);

        return response()->json($orders);
    }

    public function show(Order $order)
    {
        $order->load([
            'user:id,name,email,role',

            'items',

            'items.seller:id,name_ar,name_en,slug',

            'statusHistory.changedBy:id,name,email',
        ]);

        return response()->json([
            'data' => $order,
        ]);
    }

    public function updateStatus(
        Request $request,
        Order $order
    ) {
        $validated = $request->validate([
            'status' => [
                'required',

                Rule::in([
                    'pending',
                    'confirmed',
                    'preparing',
                    'out_for_delivery',
                    'delivered',
                    'cancelled',
                ]),
            ],

            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $order = DB::transaction(function () use (
            $request,
            $order,
            $validated
        ) {
            $lockedOrder = Order::query()
                ->where('id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus =
                $lockedOrder->status;

            $newStatus =
                $validated['status'];

            if ($oldStatus === $newStatus) {
                return $lockedOrder;
            }

            $allowedTransitions = [
                'pending' => [
                    'confirmed',
                    'cancelled',
                ],

                'confirmed' => [
                    'preparing',
                    'cancelled',
                ],

                'preparing' => [
                    'out_for_delivery',
                    'cancelled',
                ],

                'out_for_delivery' => [
                    'delivered',
                ],

                'delivered' => [],

                'cancelled' => [],
            ];

            if (
                ! in_array(
                    $newStatus,
                    $allowedTransitions[
                        $oldStatus
                    ] ?? [],
                    true
                )
            ) {
                abort(
                    422,
                    "Cannot change order from {$oldStatus} to {$newStatus}."
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Restore stock only when moving to cancelled
            |--------------------------------------------------------------------------
            */

            if ($newStatus === 'cancelled') {
                $lockedOrder->load('items');

                foreach (
                    $lockedOrder->items as $item
                ) {
                    if (! $item->product_id) {
                        continue;
                    }

                    $product = Product::query()
                        ->where(
                            'id',
                            $item->product_id
                        )
                        ->lockForUpdate()
                        ->first();

                    if ($product) {
                        $product->increment(
                            'stock',
                            $item->quantity
                        );
                    }
                }
            }

            $update = [
                'status' => $newStatus,
            ];

            if (
                $newStatus === 'confirmed'
            ) {
                $update['confirmed_at'] =
                    now();
            }

            if (
                $newStatus === 'delivered'
            ) {
                $update['delivered_at'] =
                    now();
            }

            if (
                $newStatus === 'cancelled'
            ) {
                $update['cancelled_at'] =
                    now();
            }

            $lockedOrder->update(
                $update
            );

            $lockedOrder
                ->statusHistory()
                ->create([
                    'changed_by' => $request->user()->id,

                    'from_status' => $oldStatus,

                    'to_status' => $newStatus,

                    'note' => $validated['note']
                        ?? null,
                ]);
            $lockedOrder->user->notify(
                new OrderStatusChangedNotification(
                    $lockedOrder,
                    $validated['note'] ?? null
                )
            );

            return $lockedOrder;
        });

        return response()->json([
            'message' => 'Order status updated successfully.',

            'data' => $order->fresh(),
        ]);
    }
}
