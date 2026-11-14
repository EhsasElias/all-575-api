<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SuperAdminMetricController extends Controller
{
    /** Only safe, explicitly selected columns are allowed out of each table. */
    private const METRICS = [
        'customers' => ['users', ['id', 'name', 'email', 'is_active', 'created_at']],
        'sellers' => ['sellers', ['id', 'name_ar', 'name_en', 'status', 'created_at']],
        'active_sellers' => ['sellers', ['id', 'name_ar', 'name_en', 'status', 'created_at'], 'status', 'approved'],
        'products' => ['products', ['id', 'seller_id', 'name_ar', 'name_en', 'status', 'created_at']],
        'published_products' => ['products', ['id', 'seller_id', 'name_ar', 'name_en', 'status', 'created_at'], 'status', 'published'],
        'orders' => ['orders', ['id', 'number', 'status', 'total_halalas', 'paid_at', 'created_at']],
        'paid_order_value' => ['orders', ['id', 'number', 'status', 'total_halalas', 'paid_at', 'created_at'], 'paid_at', '__not_null__'],
        'paid_orders' => ['orders', ['id', 'number', 'status', 'total_halalas', 'paid_at', 'created_at'], 'paid_at', '__not_null__'],
        'awaiting_payment' => ['orders', ['id', 'number', 'status', 'total_halalas', 'paid_at', 'created_at'], 'status', 'pending_payment'],
        'confirmed_orders' => ['orders', ['id', 'number', 'status', 'total_halalas', 'paid_at', 'created_at'], 'status', 'confirmed'],
        'completed_orders' => ['orders', ['id', 'number', 'status', 'total_halalas', 'paid_at', 'created_at'], 'status', 'completed'],
        'ready_for_delivery' => ['suborders', ['id', 'order_id', 'seller_id', 'branch_id', 'status', 'created_at'], 'status', 'ready'],
        'pending_seller_applications' => ['seller_applications', ['id', 'seller_id', 'status', 'created_at'], 'status', 'submitted'],
        'pending_product_reviews' => ['products', ['id', 'seller_id', 'name_ar', 'name_en', 'status', 'created_at'], 'status', 'pending_review'],
        'pending_category_requests' => ['seller_category_requests', ['id', 'seller_id', 'name_ar', 'name_en', 'status', 'created_at'], 'status', 'pending'],
    ];

    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()?->is_active && DB::table('platform_super_admin_grants')
            ->where('user_id', $request->user()->id)->where('is_active', true)->exists(), 403);
    }

    /** @return array{0: \Illuminate\Database\Query\Builder, 1: array<int, string>} */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'seller_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:50', 'regex:/^[a-z_]+$/'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);
    }

    private function metricQuery(string $metric, array $filters = []): array
    {
        abort_unless(array_key_exists($metric, self::METRICS), 404);
        [$table, $suggested] = self::METRICS[$metric];
        $columns = array_values(array_filter($suggested, static fn (string $column): bool => Schema::hasColumn($table, $column)));
        $query = DB::table($table)->select($columns);
        if (isset(self::METRICS[$metric][2], self::METRICS[$metric][3])) {
            $field = self::METRICS[$metric][2];
            $value = self::METRICS[$metric][3];
            if ($value === '__not_null__') {
                $query->whereNotNull($field);
            } else {
                $query->where($field, $value);
            }
        }
        if (in_array($metric, ['paid_order_value', 'paid_orders'], true)) {
            $query->whereIn('status', ['confirmed', 'completed']);
        }
        // Apply the same validated restrictions to paginated details and exports.
        if (isset($filters['from'])) {
            $query->whereDate($table . '.created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->whereDate($table . '.created_at', '<=', $filters['to']);
        }
        if (isset($filters['seller_id'])) {
            if (Schema::hasColumn($table, 'seller_id')) {
                $query->where($table . '.seller_id', $filters['seller_id']);
            } elseif ($table === 'sellers') {
                $query->where('sellers.id', $filters['seller_id']);
            } elseif ($table === 'orders') {
                $query->whereExists(function ($sub) use ($filters): void {
                    $sub->selectRaw('1')->from('suborders')
                        ->whereColumn('suborders.order_id', 'orders.id')
                        ->where('suborders.seller_id', $filters['seller_id']);
                });
            } else {
                abort(422, 'Seller filter is not available for this statistic.');
            }
        }
        if (isset($filters['status'])) {
            abort_unless(Schema::hasColumn($table, 'status'), 422);
            $query->where($table . '.status', $filters['status']);
        }
        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $term = '%' . addcslashes(trim($filters['q']), '%_\\\\') . '%';
            $searchable = array_values(array_intersect(
                ['name', 'name_ar', 'name_en', 'email', 'number', 'status'],
                $columns
            ));
            abort_if($searchable === [], 422, 'Search is not available for this statistic.');
            $query->where(function ($inner) use ($searchable, $table, $term): void {
                foreach ($searchable as $column) {
                    $inner->orWhere($table . '.' . $column, 'like', $term);
                }
            });
        }
        return [$query, $columns];
    }

    public function index(Request $request, string $metric): JsonResponse
    {
        $this->authorizeOwner($request);
        $input = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        [$query, $columns] = $this->metricQuery($metric, $this->validatedFilters($request));
        $page = $query->orderByDesc('id')->paginate(
            (int) ($input['per_page'] ?? 15), $columns, 'page', (int) ($input['page'] ?? 1)
        );
        return response()->json(['data' => $page->items(), 'columns' => $columns,
            'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()]);
    }

    private function safeSpreadsheetCell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        // Treat exported values as data, never spreadsheet formulas.
        return preg_match('/^\\s*[=+@\\-]/u', $text) === 1 ? "'" . $text : $text;
    }

    public function export(Request $request, string $metric, string $format): StreamedResponse
    {
        $this->authorizeOwner($request);
        abort_unless(in_array($format, ['csv', 'xls'], true), 404);
        [$query, $columns] = $this->metricQuery($metric, $this->validatedFilters($request));
        // Export full matching data via cursor without collecting the entire result in memory.
        $filename = 'all575-' . $metric . '-' . now()->format('Ymd-His') . '.' . $format;

        return response()->streamDownload(function () use ($query, $columns, $format): void {
            if ($format === 'csv') {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, $columns);
                foreach ($query->orderBy('id')->cursor() as $row) {
                    fputcsv($out, array_map(fn ($col) => $this->safeSpreadsheetCell($row->$col ?? ''), $columns));
                }
                fclose($out);
                return;
            }
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Report"><Table>';
            $writeRow = static function (array $values): void {
                echo '<Row>';
                foreach ($values as $value) {
                    $safe = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    echo '<Cell><Data ss:Type="String">' . $safe . '</Data></Cell>';
                }
                echo '</Row>';
            };
            $writeRow($columns);
            foreach ($query->orderBy('id')->cursor() as $row) {
                $writeRow(array_map(static fn ($col) => $row->$col ?? '', $columns));
            }
            echo '</Table></Worksheet></Workbook>';
        }, $filename, ['Content-Type' => $format === 'csv'
            ? 'text/csv; charset=UTF-8' : 'application/vnd.ms-excel; charset=UTF-8',
            'Cache-Control' => 'private, no-store']);
    }
}
