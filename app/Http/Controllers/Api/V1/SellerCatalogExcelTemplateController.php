<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Editable Excel import template. Dropdown options are populated from the
 * active categories and the stores the signed-in seller can actually manage.
 */
final class SellerCatalogExcelTemplateController extends Controller
{
    private const HEADERS = [
        'رمز SKU (إجباري)', 'الباركود (اختياري)', 'المتجر (اختياري)',
        'التصنيف (إجباري)', 'اسم المنتج بالعربية (إجباري)',
        'اسم المنتج بالإنجليزية (إجباري)', 'الوصف بالعربية (اختياري)',
        'الوصف بالإنجليزية (اختياري)', 'السعر بالريال (1–10) (إجباري)',
        'فئة الضريبة (إجباري)',
        'اسم وحدة البيع بالعربية (اختياري)', 'اسم وحدة البيع بالإنجليزية (اختياري)',
    ];

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function cell(string $ref, string $text, int $style = 0): string
    {
        return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.
            self::xml($text).'</t></is></c>';
    }

    public function __invoke(Request $request): BinaryFileResponse
    {
        $userId = $request->user()->id;
        $sellers = DB::table('sellers')
            ->where('status', 'approved')
            ->whereExists(function ($query) use ($userId): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'sellers.id')
                    ->where('seller_staff_access.user_id', $userId)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager']);
            })
            ->orderBy('id')->limit(100)->get(['id', 'name_ar']);
        abort_if($sellers->isEmpty(), 403);

        $categoryRecords = DB::table('categories')->where('is_active', true)
            ->orderBy('id')->limit(1000)->get(['id', 'name_ar', 'name_en']);
        $counts = [];
        foreach ($categoryRecords as $category) {
            foreach ([(string) $category->name_ar, (string) $category->name_en] as $name) {
                if (trim($name) !== '') $counts[trim($name)] = ($counts[trim($name)] ?? 0) + 1;
            }
        }
        $categories = $categoryRecords->map(static function ($category) use ($counts): string {
            $name = trim((string) $category->name_ar);
            return $name !== '' && ($counts[$name] ?? 0) === 1 ? $name : (string) $category->id;
        })->all();
        $storeOptions = $sellers->map(fn ($seller): string =>
            trim((string) $seller->name_ar) !== '' ? $seller->name_ar : (string) $seller->id
        )->all();
        $vatOptions = ['قياسية', 'صفرية', 'معفاة'];
        $lists = ['N' => $categories, 'O' => $storeOptions, 'P' => $vatOptions];
        $rows = '<row r="1" ht="34" customHeight="1">';
        foreach (self::HEADERS as $index => $header) {
            $rows .= self::cell(chr(65 + $index).'1', $header, 1);
        }
        $rows .= '</row>';

        // Put the selectable values in hidden columns on this sheet: Excel list
        // validation accepts same-sheet references even without named ranges.
        $count = max(1, ...array_values(array_map('count', $lists)));
        for ($row = 2; $row <= $count + 1; ++$row) {
            $cells = '';
            foreach ($lists as $column => $values) {
                if (isset($values[$row - 2])) {
                    $cells .= self::cell($column.$row, (string) $values[$row - 2]);
                }
            }
            if ($cells !== '') $rows .= '<row r="'.$row.'">'.$cells.'</row>';
        }

        $validations = '';
        foreach (['C' => 'O', 'D' => 'N', 'J' => 'P'] as $target => $source) {
            $last = max(2, count($lists[$source]) + 1);
            $validations .= '<dataValidation type="list" allowBlank="1" showDropDown="0" '.
                'showErrorMessage="1" errorTitle="اختيار غير صالح" error="اختر قيمة من القائمة" '.
                'sqref="'.$target.'2:'.$target.'201"><formula1>$'.$source.'$2:$'.$source.'$'.$last.'</formula1></dataValidation>';
        }
        // Price is entered in SAR and must match the importer price rules.
        $validations .= '<dataValidation type="decimal" operator="between" allowBlank="0" '.
            'showErrorMessage="1" error="السعر من 1 إلى 10 ريال" sqref="I2:I201">'.
            '<formula1>1</formula1><formula2>10</formula2></dataValidation>';

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
            '<sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews>'.
            '<sheetFormatPr defaultRowHeight="23"/><cols>'.
            '<col min="1" max="2" width="24" customWidth="1"/>'.
            '<col min="3" max="4" width="22" customWidth="1"/>'.
            '<col min="5" max="8" width="35" customWidth="1"/>'.
            '<col min="9" max="12" width="28" customWidth="1"/>'.
            '<col min="14" max="16" hidden="1"/>'.
            '</cols><sheetData>'.$rows.'</sheetData>'.
            '<autoFilter ref="A1:L201"/>'.
            '<dataValidations count="4">'.$validations.'</dataValidations>'.
            '</worksheet>';

        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
            '<fonts count="2"><font><sz val="11"/><name val="Arial"/></font>'.
            '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Arial"/></font></fonts>'.
            '<fills count="3"><fill><patternFill patternType="none"/></fill>'.
            '<fill><patternFill patternType="gray125"/></fill>'.
            '<fill><patternFill patternType="solid"><fgColor rgb="FF176B52"/><bgColor indexed="64"/></patternFill></fill></fills>'.
            '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'.
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'.
            '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'.
            '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1">'.
            '<alignment horizontal="right" vertical="center" wrapText="1"/></xf></cellXfs>'.
            '</styleSheet>';

        $file = tempnam(sys_get_temp_dir(), 'seller-template-');
        abort_if($file === false, 500);
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) abort(500);
        try {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?>'.
                '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
                '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
                '<Default Extension="xml" ContentType="application/xml"/>'.
                '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
                '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
                '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.
                '</Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0"?>'.
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
                '</Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?>'.
                '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '.
                'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
                '<sheets><sheet name="المنتجات" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?>'.
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'.
                '</Relationships>');
            $zip->addFromString('xl/styles.xml', $styles);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
        } finally {
            $zip->close();
        }
        return response()->download($file, 'seller-products-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store',
        ])->deleteFileAfterSend(true);
    }
}
