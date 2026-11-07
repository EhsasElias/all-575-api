<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Orders\Enums\SuborderStatus;
use App\Domain\Orders\Models\Suborder;
use App\Domain\Orders\Services\SuborderTransitions;
use App\Http\Controllers\Controller;
use App\Domain\Reporting\Services\XlsxWriter;
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
        $counts = (clone $base)->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')->pluck('total', 'status');
        $delivered = (clone $base)->where('status', SuborderStatus::Delivered->value)
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(subtotal_halalas - discount_halalas), 0) as merchandise_halalas')
            ->first();

        return response()->json(['data' => [
            'awaiting_seller' => (int) ($counts['awaiting_seller'] ?? 0),
            'preparing' => (int) ($counts['preparing'] ?? 0),
            'ready' => (int) ($counts['ready'] ?? 0),
            'on_the_way' => (int) ($counts['on_the_way'] ?? 0),
            'delivered' => (int) ($counts['delivered'] ?? 0),
            'delivered_merchandise_halalas' => (int) ($delivered?->merchandise_halalas ?? 0),
            'currency' => 'SAR',
        ]]);
    }

    public function orders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'before_id' => ['sometimes', 'ulid'],
            'status' => ['sometimes', Rule::in(array_map(
                fn (SuborderStatus $status): string => $status->value,
                SuborderStatus::cases()
            ))],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $orders = $this->scopedOrders($request)
            ->whereHas('order', fn ($query) => $query->whereIn('status', ['confirmed', 'completed']))
            ->with(['items', 'branch', 'order:id,number,status,placed_at,slot_starts_at'])
            ->when(isset($validated['status']), fn (Builder $query) => $query->where('status', $validated['status']))
            ->when(isset($validated['branch_id']), fn (Builder $query) => $query->where('branch_id', $validated['branch_id']))
            ->when(isset($validated['from']), fn (Builder $query) => $query->whereHas('order',
                fn (Builder $orders) => $orders->whereDate('placed_at', '>=', $validated['from'])))
            ->when(isset($validated['to']), fn (Builder $query) => $query->whereHas('order',
                fn (Builder $orders) => $orders->whereDate('placed_at', '<=', $validated['to'])))
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

    public function showOrder(Request $request, string $suborder): JsonResponse
    {
        $delivery = $this->scopedOrders($request)
            ->whereKey($suborder)
            ->whereHas('order', fn ($query) => $query->whereIn('status', ['confirmed', 'completed']))
            ->with(['items', 'order:id,number,status,placed_at,slot_starts_at'])
            ->firstOrFail();

        return response()->json(['data' => [
            'id' => $delivery->id,
            'order_number' => $delivery->order->number,
            'status' => $delivery->status->value,
            'branch_id' => $delivery->branch_id,
            'placed_at' => $delivery->order->placed_at,
            'delivery_slot' => $delivery->order->slot_starts_at,
            'items' => $delivery->items->map(fn ($item): array => [
                'id' => $item->id,
                'name' => ['ar' => $item->name_ar, 'en' => $item->name_en],
                'quantity' => $item->quantity,
            ])->all(),
        ]]);
    }

    public function packingList(Request $request, string $suborder): JsonResponse
    {
        $delivery = $this->scopedOrders($request)
            ->whereKey($suborder)
            ->whereHas('order', fn ($query) => $query->whereIn('status', ['confirmed', 'completed']))
            ->with(['items', 'order:id,number,placed_at'])
            ->firstOrFail();

        return response()->json(['data' => [
            'order_number' => $delivery->order->number,
            'suborder_id' => $delivery->id,
            'branch_id' => $delivery->branch_id,
            'status' => $delivery->status->value,
            'created_at' => $delivery->order->placed_at,
            'items' => $delivery->items->map(fn ($item): array => [
                'product' => ['ar' => $item->name_ar, 'en' => $item->name_en],
                'variant' => ['ar' => $item->variant_name_ar, 'en' => $item->variant_name_en],
                'quantity' => (int) $item->quantity,
                'variant_id' => $item->variant_id,
            ])->all(),
        ]]);
    }

    public function salesSummary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = $this->scopedOrders($request)
            ->where('status', SuborderStatus::Delivered->value)
            ->whereHas('order', fn (Builder $orders) => $orders
                ->whereDate('placed_at', '>=', $filters['from'])
                ->whereDate('placed_at', '<=', $filters['to']));
        if (isset($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        $summary = $query->selectRaw(
            'COUNT(*) as delivered_suborders, COALESCE(SUM(subtotal_halalas), 0) as subtotal_halalas, '.
            'COALESCE(SUM(discount_halalas), 0) as discount_halalas'
        )->first();

        return response()->json(['data' => [
            'from' => $filters['from'],
            'to' => $filters['to'],
            'currency' => 'SAR',
            'delivered_suborders' => (int) $summary->delivered_suborders,
            'subtotal_halalas' => (int) $summary->subtotal_halalas,
            'discount_halalas' => (int) $summary->discount_halalas,
            'net_merchandise_halalas' => (int) $summary->subtotal_halalas - (int) $summary->discount_halalas,
            'note' => 'Merchandise totals only. This is not a commission or seller payout statement.',
        ]]);
    }

    /** Use one filtered, membership-scoped query for CSV and Excel. */
    private function exportOrdersQuery(Request $request): Builder
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(array_map(
                fn (SuborderStatus $status): string => $status->value,
                SuborderStatus::cases()
            ))],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = $this->scopedOrders($request)
            ->whereHas('order', fn ($orders) => $orders->whereIn('status', ['confirmed', 'completed']))
            ->with('order:id,number,placed_at');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
        if (isset($filters['from'])) {
            $query->whereHas('order', fn ($orders) => $orders->whereDate('placed_at', '>=', $filters['from']));
        }
        if (isset($filters['to'])) {
            $query->whereHas('order', fn ($orders) => $orders->whereDate('placed_at', '<=', $filters['to']));
        }

        return $query;
    }

    private function orderExportLocale(Request $request): string
    {
        $value = (string) $request->input('lang', $request->header('Accept-Language', 'ar'));
        return str_starts_with(strtolower($value), 'ar') ? 'ar' : 'en';
    }

    private function orderExportHeaders(string $locale): array
    {
        return $locale === 'ar'
            ? ['رقم الطلب', 'حالة الطلب', 'الفرع', 'تاريخ الطلب']
            : ['Order', 'Status', 'Branch', 'Date'];
    }

    private function orderExportRow(Suborder $suborder, string $locale): array
    {
        $labels = $locale === 'ar'
            ? [
                'awaiting_seller' => 'بانتظار البائع', 'preparing' => 'قيد التجهيز',
                'ready' => 'جاهز', 'on_the_way' => 'في الطريق', 'delivered' => 'تم التسليم',
                'cancelled' => 'ملغي',
            ]
            : [
                'awaiting_seller' => 'Awaiting seller', 'preparing' => 'Preparing',
                'ready' => 'Ready', 'on_the_way' => 'On the way', 'delivered' => 'Delivered',
                'cancelled' => 'Cancelled',
            ];
        $status = $suborder->status->value;
        $branch = $suborder->branch;
        $branchName = $branch !== null
            ? ($locale === 'ar' ? $branch->name_ar : $branch->name_en)
            : null;
        return [
            $suborder->order->number,
            $labels[$status] ?? $status,
            $branchName ?: '#'.$suborder->branch_id,
            (string) $suborder->order->placed_at,
        ];
    }

    public function exportOrdersExcel(Request $request, XlsxWriter $xlsx): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $locale = $this->orderExportLocale($request);
        $rows = $this->exportOrdersQuery($request)->with('branch:id,name_ar,name_en')
            ->orderByDesc('id')->limit(5000)->lazy(200)
            ->map(fn (Suborder $suborder): array => $this->orderExportRow($suborder, $locale));

        return $xlsx->download($this->orderExportHeaders($locale), $rows, 'seller-orders.xlsx');
    }

    public function exportOrders(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $locale = $this->orderExportLocale($request);
        $query = $this->exportOrdersQuery($request)->with('branch:id,name_ar,name_en');

        return response()->streamDownload(function () use ($query, $locale): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $this->orderExportHeaders($locale));
            foreach ($query->orderByDesc('id')->limit(5000)->lazy(200) as $suborder) {
                // Avoid spreadsheet formula execution when CSV is opened in Excel.
                $cells = array_map(static function ($value): string {
                    $text = (string) $value;
                    return preg_match('/^[=+@\\-\\t\\r]/u', $text) ? "'".$text : $text;
                }, $this->orderExportRow($suborder, $locale));
                fputcsv($handle, $cells);
            }
            fclose($handle);
        }, 'seller-orders.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
