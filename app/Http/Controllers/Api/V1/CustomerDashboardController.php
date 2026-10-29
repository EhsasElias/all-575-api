<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Aftercare\Models\CustomerCase;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\CustomerRequest;
use App\Domain\Payments\Models\Refund;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerDashboardResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerDashboardController extends Controller
{
    /** The signed-in customer's all-time summary; no other customer's records are queried. */
    public function __invoke(Request $request): CustomerDashboardResource
    {
        $user = $request->user();
        $counts = $user->orders()->selectRaw("COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END), 0) AS active,
            COALESCE(SUM(CASE WHEN status = 'pending_payment' THEN 1 ELSE 0 END), 0) AS awaiting_payment,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END), 0) AS completed")->first();
        $paid = (int) $user->orders()->whereNotNull('paid_at')->sum('total_halalas');
        $refunded = (int) Refund::query()->where('status', 'succeeded')
            ->whereHas('order', fn (Builder $order) => $order->where('user_id', $user->id)->whereNotNull('paid_at'))
            ->sum('amount_halalas');

        return new CustomerDashboardResource([
            'orders_total' => (int) $counts->total,
            'orders_active' => (int) $counts->active,
            'orders_awaiting_payment' => (int) $counts->awaiting_payment,
            'orders_completed' => (int) $counts->completed,
            'paid_total' => $paid,
            'refunded_total' => $refunded,
            'net_spent' => $paid - $refunded,
            'currency' => config('store.currency'),
            'generated_at' => now()->toIso8601String(),
            'saved_products' => Product::query()->visible()->whereIn('id', DB::table('customer_saved_products')->where('user_id', $user->id)->select('product_id'))->count(),
            'addresses' => $user->addresses()->count(),
            'open_support' => CustomerCase::query()->where('user_id', $user->id)->whereIn('status', ['submitted', 'in_review'])->count(),
            'open_requests' => CustomerRequest::query()->where('user_id', $user->id)->whereIn('status', ['submitted', 'under_review'])->count(),
            'recent_orders' => $user->orders()->with(['items', 'payments'])->latest('placed_at')->latest('id')->limit(3)->get(),
        ]);
    }
}
