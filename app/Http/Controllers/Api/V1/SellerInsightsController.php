<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Services\XlsxWriter;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seller-scoped, read-only drilldowns. These are merchandise figures, not profit
 * or amounts owed: cost of goods, commission, fulfilment and refunds are not
 * fully accounted for in the source order records.
 */
final class SellerInsightsController extends Controller
{
    private const METRICS = [
        'orders', 'awaiting_seller', 'preparing', 'ready', 'on_the_way',
        'delivered', 'sales', 'products', 'branches', 'stock',
    ];

    private function filters(Request $request): array
    {
        return $request->validate([
            'metric' => ['required', Rule::in(self::METRICS)],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
        ]);
    }

    private function allowedOrders(Request $request, array $filters): Builder
    {
        $userId = $request->user()->id;
        return DB::table('suborders')
            ->join('orders', 'orders.id', '=', 'suborders.order_id')
            ->join('branches', 'branches.id', '=', 'suborders.branch_id')
            ->join('sellers', 'sellers.id', '=', 'suborders.seller_id')
            ->where('sellers.status', 'approved')
            ->whereIn('orders.status', ['confirmed', 'completed'])
            ->where('orders.currency', 'SAR')
            ->whereDate('orders.placed_at', '>=', $filters['from'])
            ->whereDate('orders.placed_at', '<=', $filters['to'])
            ->when(isset($filters['branch_id']),
                fn (Builder $q) => $q->where('suborders.branch_id', $filters['branch_id']))
            ->whereExists(function ($query) use ($userId): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'suborders.seller_id')
                    ->where('seller_staff_access.user_id', $userId)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager', 'picker'])
                    ->where(function ($scope): void {
                        $scope->whereNull('seller_staff_access.branch_id')
                            ->orWhereColumn('seller_staff_access.branch_id', 'suborders.branch_id');
                    });
            });
    }

    private function metricOrders(Builder $orders, string $metric): Builder
    {
        if ($metric === 'sales' || $metric === 'products') {
            return $orders->where('suborders.status', 'delivered');
        }
        if (in_array($metric, ['awaiting_seller', 'preparing', 'ready', 'on_the_way', 'delivered'], true)) {
            return $orders->where('suborders.status', $metric);
        }
        return $orders;
    }

    private function rows(Request $request, array $filters): array
    {
        $metric = $filters['metric'];
        $base = $this->metricOrders($this->allowedOrders($request, $filters), $metric);

        if ($metric === 'products') {
            $records = $base->join('order_items', 'order_items.suborder_id', '=', 'suborders.id')
                ->selectRaw('order_items.product_id as id, order_items.name_ar, order_items.name_en, SUM(order_items.quantity) as units, SUM(order_items.line_total_halalas) as sales_halalas')
                ->groupBy('order_items.product_id', 'order_items.name_ar', 'order_items.name_en')
                ->orderByDesc('units')->limit(100)->get();
            return [
                ['Product ID', 'Arabic name', 'English name', 'Units', 'Merchandise SAR'],
                $records->map(static fn ($row) => [
                    (string) $row->id, $row->name_ar, $row->name_en, (int) $row->units,
                    number_format(((int) $row->sales_halalas) / 100, 2, '.', ''),
                ])->all(),
            ];
        }

        if ($metric === 'branches') {
            $records = $base->selectRaw('branches.id, branches.name_ar, branches.name_en, COUNT(*) as orders, SUM(CASE WHEN suborders.status = \'delivered\' THEN suborders.subtotal_halalas - suborders.discount_halalas ELSE 0 END) as delivered_sales_halalas')
                ->groupBy('branches.id', 'branches.name_ar', 'branches.name_en')
                ->orderByDesc('orders')->limit(100)->get();
            return [
                ['Branch ID', 'Arabic name', 'English name', 'Orders', 'Delivered merchandise SAR'],
                $records->map(static fn ($row) => [
                    (string) $row->id, $row->name_ar, $row->name_en, (int) $row->orders,
                    number_format(((int) $row->delivered_sales_halalas) / 100, 2, '.', ''),
                ])->all(),
            ];
        }

        if ($metric === 'stock') {
            $userId = $request->user()->id;
            $records = DB::table('branch_stock')
                ->join('branches', 'branches.id', '=', 'branch_stock.branch_id')
                ->join('sellers', 'sellers.id', '=', 'branches.seller_id')
                ->join('product_variants', 'product_variants.id', '=', 'branch_stock.variant_id')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('sellers.status', 'approved')
                ->whereColumn('products.seller_id', 'branches.seller_id')
                ->when(isset($filters['branch_id']),
                    fn (Builder $q) => $q->where('branches.id', $filters['branch_id']))
                ->whereExists(function ($query) use ($userId): void {
                    $query->selectRaw('1')->from('seller_staff_access')
                        ->whereColumn('seller_staff_access.seller_id', 'branches.seller_id')
                        ->where('seller_staff_access.user_id', $userId)
                        ->where('seller_staff_access.is_active', true)
                        ->whereIn('seller_staff_access.role', ['owner', 'branch_manager', 'picker'])
                        ->where(function ($scope): void {
                            $scope->whereNull('seller_staff_access.branch_id')
                                ->orWhereColumn('seller_staff_access.branch_id', 'branches.id');
                        });
                })
                ->select('branches.name_ar as branch', 'product_variants.sku', 'products.name_ar',
                    'branch_stock.on_hand', 'branch_stock.reserved')
                ->orderBy('branch_stock.id')->limit(200)->get();
            return [
                ['Branch', 'SKU', 'Product', 'On hand', 'Reserved', 'Available'],
                $records->map(static fn ($row) => [
                    $row->branch, $row->sku, $row->name_ar,
                    (int) $row->on_hand, (int) $row->reserved,
                    (int) $row->on_hand - (int) $row->reserved,
                ])->all(),
            ];
        }

        $records = $base
            ->select('suborders.id', 'orders.number as order_number', 'orders.placed_at',
                'branches.name_ar as branch', 'suborders.status', 'suborders.subtotal_halalas',
                'suborders.discount_halalas')
            ->orderByDesc('orders.placed_at')->orderByDesc('suborders.id')
            ->limit(200)->get();
        return [
            ['Order', 'Date', 'Branch', 'Status', 'Subtotal SAR', 'Discount SAR', 'Merchandise SAR'],
            $records->map(static fn ($row) => [
                $row->order_number, (string) $row->placed_at, $row->branch, $row->status,
                number_format(((int) $row->subtotal_halalas) / 100, 2, '.', ''),
                number_format(((int) $row->discount_halalas) / 100, 2, '.', ''),
                number_format(((int) $row->subtotal_halalas - (int) $row->discount_halalas) / 100, 2, '.', ''),
            ])->all(),
        ];
    }

    /** Daily sales trend, grouped by order placement date; only delivered merchandise. */
    public function trend(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $rows = $this->allowedOrders($request, $filters)
            ->where('suborders.status', 'delivered')
            ->selectRaw('DATE(orders.placed_at) as day, COUNT(*) as order_count, '.
                'COALESCE(SUM(suborders.subtotal_halalas - suborders.discount_halalas), 0) as merchandise_halalas')
            ->groupByRaw('DATE(orders.placed_at)')
            ->orderByDesc('day')
            ->limit(180)
            ->get();

        return response()->json(['data' => $rows->reverse()->values()->map(static fn ($row): array => [
            'day' => $row->day,
            'orders' => (int) $row->order_count,
            'merchandise_halalas' => (int) $row->merchandise_halalas,
        ])->all(), 'note' => 'Delivered orders grouped by original placement date, not payout date.']);
    }

    public function show(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        [$columns, $rows] = $this->rows($request, $filters);
        return response()->json(['data' => [
            'metric' => $filters['metric'],
            'from' => $filters['from'],
            'to' => $filters['to'],
            'columns' => $columns,
            'rows' => $rows,
            'returned_rows' => count($rows),
            'max_rows' => $filters['metric'] === 'stock' || !in_array($filters['metric'], ['products', 'branches'], true) ? 200 : 100,
            'note' => 'Merchandise only; not profit or payout. Rows are capped; narrow your dates/branch for complete exports.',
        ]]);
    }

    public function export(Request $request, string $format, XlsxWriter $xlsx): Response
    {
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 404);
        $filters = $this->filters($request);
        [$columns, $rows] = $this->rows($request, $filters);
        $filename = 'seller-'.$filters['metric'].'-'.$filters['from'].'-'.$filters['to'].'.'.$format;
        if ($format === 'xlsx') {
            return $xlsx->download($columns, $rows, $filename);
        }

        return response()->streamDownload(static function () use ($columns, $rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);
            foreach ($rows as $row) {
                // Prevent spreadsheet formula injection when CSV is opened in Excel.
                fputcsv($out, array_map(static function ($cell): string {
                    $value = (string) $cell;
                    return preg_match('/^[=+@\-\t\r]/u', $value) ? "'".$value : $value;
                }, $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
