<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Services\AuditLogger;
use App\Domain\Reporting\Services\CsvWriter;
use App\Domain\Reporting\Services\SpendingReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SpendingReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SpendingReportController extends Controller
{
    public function show(SpendingReportRequest $request, SpendingReport $report): JsonResponse
    {
        $data = $report->for($request->user(), $request->from(), $request->to());

        return response()->json(['data' => $data + [
            'currency' => config('store.currency'),
            'timezone' => config('store.slots.timezone'),
            'from' => $request->from()->toDateString(),
            'to' => $request->to()->toDateString(),
        ]]);
    }

    public function csv(SpendingReportRequest $request, SpendingReport $report, CsvWriter $csv, AuditLogger $audit): Response
    {
        $data = $report->for($request->user(), $request->from(), $request->to());
        $tz = config('store.slots.timezone');
        $date = fn ($at): string => $at === null ? '' : $at->copy()->setTimezone($tz)->format('Y-m-d H:i');

        $rows = array_map(fn (array $row): array => [
            $row['order_number'], $row['status'], $date($row['order_date']), $date($row['payment_date']), $date($row['refund_date']),
            CsvWriter::sar($row['subtotal']), CsvWriter::sar($row['discount']), CsvWriter::sar($row['delivery_fee']),
            CsvWriter::sar($row['vat_included']), CsvWriter::sar($row['total']), CsvWriter::sar($row['refunded']), CsvWriter::sar($row['net_spent']),
        ], $data['rows']);

        $audit->record('report.exported', $request->user()->id, $request->user(), ['report' => 'spending', 'rows' => count($rows)]);

        return response($csv->write([
            'Order', 'Status', 'Order date', 'Payment date', 'Refund date',
            'Subtotal (SAR)', 'Discount (SAR)', 'Delivery fee (SAR)', 'VAT included (SAR)', 'Total (SAR)', 'Refunded (SAR)', 'Net spent (SAR)',
        ], $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="all575-spending-'.$request->from()->toDateString().'-to-'.$request->to()->toDateString().'.csv"',
        ]);
    }
}
