<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Services\AuditLogger;
use App\Domain\Reporting\Services\CsvWriter;
use App\Domain\Reporting\Services\XlsxWriter;
use App\Domain\Reporting\Services\SpendingReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SpendingReportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class SpendingReportController extends Controller
{
    /**
     * @response array{data: array{summary: array{orders: int, paid: int, refunded: int, net_spent: int, savings: int, vat_included: int, delivery_fees: int}, rows: list<array{order_number: string, status: string, order_date: string, payment_date: ?string, refund_date: ?string, subtotal: int, discount: int, delivery_fee: int, vat_included: int, total: int, refunded: int, net_spent: int}>, currency: string, timezone: string, from: string, to: string}}
     */
    public function show(SpendingReportRequest $request, SpendingReport $report): JsonResponse
    {
        $data = $report->for($request->user(), $request->from(), $request->to(), $request->safe()->only(['category', 'seller', 'status']));

        return response()->json(['data' => $data + [
            'currency' => config('store.currency'),
            'timezone' => config('store.slots.timezone'),
            'from' => $request->from()->toDateString(),
            'to' => $request->to()->toDateString(),
        ]]);
    }

    public function csv(SpendingReportRequest $request, SpendingReport $report, CsvWriter $csv, AuditLogger $audit): Response
    {
        $data = $report->for($request->user(), $request->from(), $request->to(), $request->safe()->only(['category', 'seller', 'status']));
        $tz = config('store.slots.timezone');
        $date = fn ($at): string => $at === null ? '' : $at->copy()->setTimezone($tz)->format('Y-m-d H:i');

        $rows = array_map(fn (array $row): array => [
            $row['order_number'], $row['status'], $date($row['order_date']), $date($row['payment_date']), $date($row['refund_date']),
            CsvWriter::sar($row['subtotal']), CsvWriter::sar($row['discount']), CsvWriter::sar($row['delivery_fee']),
            CsvWriter::sar($row['vat_included']), CsvWriter::sar($row['total']), CsvWriter::sar($row['refunded']), CsvWriter::sar($row['net_spent']),
        ], $data['rows']);

        $audit->record('report.exported', $request->user()->id, $request->user(), ['report' => 'spending', 'rows' => count($rows)]);

        return response($csv->write([
            'رقم الطلب', 'حالة الطلب', 'تاريخ الطلب', 'تاريخ الدفع', 'تاريخ الاسترداد',
            'المجموع قبل الخصم (ريال)', 'الخصم (ريال)', 'رسوم التوصيل (ريال)', 'الضريبة المضمنة (ريال)', 'الإجمالي (ريال)', 'المبلغ المسترد (ريال)', 'صافي المصروفات (ريال)',
        ], $rows), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="all575-spending-'.$request->from()->toDateString().'-to-'.$request->to()->toDateString().'.csv"',
        ]);
    }
    public function excel(SpendingReportRequest $request, SpendingReport $report, XlsxWriter $xlsx, AuditLogger $audit): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $data = $report->for($request->user(), $request->from(), $request->to(), $request->safe()->only(['category', 'seller', 'status']));
        $tz = config('store.slots.timezone');
        $date = fn ($at): string => $at === null ? '' : $at->copy()->setTimezone($tz)->format('Y-m-d H:i');
        $rows = array_map(fn (array $row): array => [
            $row['order_number'], $row['status'], $date($row['order_date']), $date($row['payment_date']), $date($row['refund_date']),
            CsvWriter::sar($row['subtotal']), CsvWriter::sar($row['discount']), CsvWriter::sar($row['delivery_fee']),
            CsvWriter::sar($row['vat_included']), CsvWriter::sar($row['total']), CsvWriter::sar($row['refunded']), CsvWriter::sar($row['net_spent']),
        ], $data['rows']);

        $audit->record('report.exported', $request->user()->id, $request->user(), ['report' => 'spending', 'format' => 'xlsx', 'rows' => count($rows)]);

        return $xlsx->download([
            'رقم الطلب', 'حالة الطلب', 'تاريخ الطلب', 'تاريخ الدفع', 'تاريخ الاسترداد',
            'المجموع قبل الخصم (ريال)', 'الخصم (ريال)', 'رسوم التوصيل (ريال)', 'الضريبة المضمنة (ريال)', 'الإجمالي (ريال)', 'المبلغ المسترد (ريال)', 'صافي المصروفات (ريال)',
        ], $rows, 'all575-spending-'.$request->from()->toDateString().'-to-'.$request->to()->toDateString().'.xlsx');
    }

}
