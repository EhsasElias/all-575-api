<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->id)
            ->withCount('items')
            ->latest('id')
            ->paginate(20);

        return response()->json($orders);
    }

    public function show(
        Request $request,
        Order $order
    ) {
        $this->ensureOwnership(
            $request,
            $order
        );

        $order->load([
            'items' => function ($query) {
                $query->select([
                    'id',
                    'order_id',
                    'product_id',
                    'seller_id',
                    'product_name_ar',
                    'product_name_en',
                    'sku',
                    'unit_price',
                    'quantity',
                    'line_total',
                ]);
            },

            'statusHistory' => function ($query) {
                $query
                    ->select([
                        'id',
                        'order_id',
                        'from_status',
                        'to_status',
                        'note',
                        'created_at',
                    ])
                    ->oldest('id');
            },
        ]);

        return response()->json([
            'data' => $order,
        ]);
    }

    public function cancel(
        Request $request,
        Order $order
    ) {
        $this->ensureOwnership(
            $request,
            $order
        );

        $request->validate([
            'note' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ]);

        $order = DB::transaction(function () use (
            $request,
            $order
        ) {
            $lockedOrder = Order::query()
                ->where('id', $order->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array(
                $lockedOrder->status,
                [
                    'pending',
                    'confirmed',
                ],
                true
            )) {
                abort(
                    422,
                    'This order can no longer be cancelled.'
                );
            }

            $lockedOrder->load('items');

            foreach ($lockedOrder->items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $product = Product::query()
                    ->where('id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($product) {
                    $product->increment(
                        'stock',
                        $item->quantity
                    );
                }
            }

            $oldStatus =
                $lockedOrder->status;

            $lockedOrder->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);

            $lockedOrder
                ->statusHistory()
                ->create([
                    'changed_by' => $request->user()->id,

                    'from_status' => $oldStatus,

                    'to_status' => 'cancelled',

                    'note' => $request->input(
                        'note',
                        'Cancelled by customer.'
                    ),
                ]);

            return $lockedOrder;
        });

        return response()->json([
            'message' => 'Order cancelled successfully.',

            'data' => $order->fresh(),
        ]);
    }

    private function ensureOwnership(
        Request $request,
        Order $order
    ): void {
        abort_unless(
            $order->user_id ===
                $request->user()->id,
            404
        );
    }
}
