<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Pricing\VatClass;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use DOMDocument;
use DOMXPath;
use ZipArchive;

/**
 * Bounded UTF-8 CSV import. Validate the whole batch before writing any product.
 * The endpoint only creates drafts; back-office approval is separate.
 */
final class SellerCatalogImportController extends Controller
{
    private const HEADERS = ['category_id', 'name_ar', 'name_en', 'price_halalas', 'vat_class', 'sku', 'barcode'];
    private const ARABIC_HEADERS = ['رقم التصنيف', 'اسم المنتج بالعربية', 'اسم المنتج بالإنجليزية', 'السعر بالهللة', 'فئة الضريبة', 'رمز المنتج SKU', 'الباركود (اختياري)'];
    // The same names and order as the Add Product form.
    private const FORM_HEADERS = [
        'رمز SKU (إجباري)', 'الباركود (اختياري)', 'المتجر (اختياري)',
        'التصنيف (إجباري)', 'اسم المنتج بالعربية (إجباري)',
        'اسم المنتج بالإنجليزية (إجباري)', 'الوصف بالعربية (اختياري)',
        'الوصف بالإنجليزية (اختياري)', 'السعر بالريال (1–10) (إجباري)',
        'فئة الضريبة (إجباري)',
    ];
    // Optional selling-unit labels correspond to the variant fields in the Add Product workflow.
    private const EXTENDED_FORM_HEADERS = [
        'رمز SKU (إجباري)', 'الباركود (اختياري)', 'المتجر (اختياري)',
        'التصنيف (إجباري)', 'اسم المنتج بالعربية (إجباري)',
        'اسم المنتج بالإنجليزية (إجباري)', 'الوصف بالعربية (اختياري)',
        'الوصف بالإنجليزية (اختياري)', 'السعر بالريال (1–10) (إجباري)',
        'فئة الضريبة (إجباري)', 'اسم وحدة البيع بالعربية (اختياري)',
        'اسم وحدة البيع بالإنجليزية (اختياري)',
    ];
    private const EXTENDED_ENGLISH_FORM_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store (optional)', 'Category (required)',
        'Arabic product name (required)', 'English product name (required)',
        'Arabic description (optional)', 'English description (optional)',
        'Price SAR (1–10) (required)', 'VAT class (required)',
        'Arabic variant name (optional)', 'English variant name (optional)',
    ];
    // Prior Arabic layout (same values, earlier captions) remains supported.
    private const PREVIOUS_FORM_HEADERS = [
        'رمز SKU (مطلوب)', 'الباركود (اختياري)', 'المتجر', 'التصنيف',
        'اسم المنتج بالعربية', 'اسم المنتج بالإنجليزية', 'الوصف بالعربية',
        'الوصف بالإنجليزية', 'السعر بالريال (1–10)', 'فئة الضريبة',
    ];
    private const LEGACY_FORM_HEADERS = [
        'رمز SKU (مطلوب)', 'الباركود (اختياري)', 'المتجر', 'التصنيف',
        'اسم المنتج بالعربية', 'اسم المنتج بالإنجليزية', 'الوصف بالعربية',
        'الوصف بالإنجليزية', 'السعر بالريال (1–5.75)', 'فئة الضريبة',
    ];
    private const LEGACY_ENGLISH_FORM_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store', 'Category',
        'Arabic product name', 'English product name', 'Arabic description',
        'English description', 'Price SAR (1–5.75)', 'VAT class',
    ];
    private const ENGLISH_FORM_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store (optional)', 'Category (required)',
        'Arabic product name (required)', 'English product name (required)',
        'Arabic description (optional)', 'English description (optional)',
        'Price SAR (1–10) (required)', 'VAT class (required)',
    ];
    private const PREVIOUS_ENGLISH_FORM_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store', 'Category',
        'Arabic product name', 'English product name', 'Arabic description',
        'English description', 'Price SAR (1–10)', 'VAT class',
    ];


    /**
     * Read the first Excel worksheet into a temporary CSV stream. Restrict the
     * input to the ten visible product columns; ignore the hidden dropdown lists.
     * Any Excel formula is rejected rather than executed or interpreted.
     */
    private function xlsxAsCsv(string $file): string
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            throw ValidationException::withMessages(['file' => ['Excel file is invalid or corrupt.']]);
        }
        try {
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($sheet === false || strlen($sheet) > 4 * 1024 * 1024) {
                throw ValidationException::withMessages(['file' => ['Excel first sheet is missing or too large.']]);
            }
            $strings = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                if (strlen($sharedXml) > 3 * 1024 * 1024) {
                    throw ValidationException::withMessages(['file' => ['Excel string table is too large.']]);
                }
                $shared = new DOMDocument();
                if (!@$shared->loadXML($sharedXml, LIBXML_NONET)) {
                    throw ValidationException::withMessages(['file' => ['Invalid Excel string table.']]);
                }
                $xp = new DOMXPath($shared);
                foreach ($xp->query('/*[local-name()="sst"]/*[local-name()="si"]') as $node) {
                    $value = '';
                    foreach ($xp->query('.//*[local-name()="t"]', $node) as $text) {
                        $value .= $text->textContent;
                    }
                    $strings[] = $value;
                }
            }
            $document = new DOMDocument();
            if (!@$document->loadXML($sheet, LIBXML_NONET)) {
                throw ValidationException::withMessages(['file' => ['Invalid Excel worksheet.']]);
            }
            $xp = new DOMXPath($document);
            $temp = tempnam(sys_get_temp_dir(), 'seller-xlsx-csv-');
            if ($temp === false) abort(500);
            $handle = fopen($temp, 'wb');
            if ($handle === false) {
                @unlink($temp);
                abort(500);
            }
            try {
                $seen = 0;
                foreach ($xp->query('/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                    $number = (int) $row->attributes?->getNamedItem('r')?->nodeValue;
                    if ($number < 1) continue;
                    $cells = array_fill(0, 10, '');
                    $hasProductCell = false;
                    foreach ($xp->query('./*[local-name()="c"]', $row) as $cell) {
                        $reference = (string) $cell->attributes?->getNamedItem('r')?->nodeValue;
                        if (!preg_match('/^([A-Z]+)[0-9]+$/', $reference, $m)) continue;
                        $column = $m[1];
                        if (strlen($column) !== 1 || $column < 'A' || $column > 'J') continue;
                        $index = ord($column) - 65;
                        if ($xp->query('./*[local-name()="f"]', $cell)->length > 0) {
                            throw ValidationException::withMessages(['file' => ['Formula cells are not allowed in product imports.']]);
                        }
                        $type = (string) $cell->attributes?->getNamedItem('t')?->nodeValue;
                        if ($type === 'inlineStr') {
                            $value = '';
                            foreach ($xp->query('.//*[local-name()="t"]', $cell) as $text) {
                                $value .= $text->textContent;
                            }
                        } else {
                            $value = (string) ($xp->query('./*[local-name()="v"]', $cell)->item(0)?->textContent ?? '');
                            if ($type === 's') {
                                $value = $strings[(int) $value] ?? '';
                            }
                        }
                        $cells[$index] = $value;
                        $hasProductCell = true;
                    }
                    if (!$hasProductCell) continue;
                    if ($number > 201 || ++$seen > 201) {
                        throw ValidationException::withMessages(['file' => ['Maximum 200 product rows per upload.']]);
                    }
                    fputcsv($handle, $cells);
                }
            } catch (\Throwable $error) {
                @unlink($temp);
                throw $error;
            } finally {
                fclose($handle);
            }
            return $temp;
        } finally {
            $zip->close();
        }
    }

    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'dry_run' => ['sometimes', 'boolean'],
            'file' => ['required', 'file', 'max:2048', 'mimes:csv,txt,xlsx'],
        ]);

        $sellerId = (int) $input['seller_id'];
        abort_unless(DB::table('sellers')->where('id', $sellerId)->where('status', 'approved')
            ->whereExists(function ($query) use ($request): void {
                $query->selectRaw('1')->from('seller_staff_access')
                    ->whereColumn('seller_staff_access.seller_id', 'sellers.id')
                    ->where('seller_staff_access.user_id', $request->user()->id)
                    ->where('seller_staff_access.is_active', true)
                    ->whereIn('seller_staff_access.role', ['owner', 'branch_manager']);
            })->exists(), 404);

        $isExcel = strtolower($input['file']->getClientOriginalExtension()) === 'xlsx';
        $converted = $isExcel ? $this->xlsxAsCsv($input['file']->getRealPath()) : null;
        $handle = fopen($converted ?? $input['file']->getRealPath(), 'rb');
        if ($handle === false) {
            if ($converted !== null) @unlink($converted);
            throw ValidationException::withMessages(['file' => ['Unable to read upload.']]);
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw ValidationException::withMessages(['file' => ['CSV is empty.']]);
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            // Friendly localized headers are converted to the internal schema by position.
            // Existing CSV files with technical headers remain fully supported.
            $extendedForm = in_array($header, [
                self::EXTENDED_FORM_HEADERS, self::EXTENDED_ENGLISH_FORM_HEADERS,
            ], true);
            $formTemplate = $extendedForm || in_array($header, [
                self::FORM_HEADERS, self::ENGLISH_FORM_HEADERS,
                self::PREVIOUS_FORM_HEADERS, self::PREVIOUS_ENGLISH_FORM_HEADERS,
                self::LEGACY_FORM_HEADERS, self::LEGACY_ENGLISH_FORM_HEADERS,
            ], true);
            if (! $formTemplate && $header !== self::HEADERS && $header !== self::ARABIC_HEADERS) {
                throw ValidationException::withMessages(['file' => [
                    'Invalid column headers. Download the latest template and keep its column order.',
                ]]);
            }
            // Store selection already belongs to the authenticated upload request. For the
            // form-aligned template the store cell can be blank, its numeric ID, or its name.
            $store = DB::table('sellers')->where('id', $sellerId)
                ->first(['name_ar', 'name_en']);
            $validStoreNames = array_filter([
                (string) $sellerId,
                trim((string) ($store?->name_ar ?? '')),
                trim((string) ($store?->name_en ?? '')),
            ], static fn (string $value): bool => $value !== '');

            // The form shows category names; accept category names or IDs in the CSV.
            // Duplicate names are ambiguous, so sellers can use a numeric ID instead.
            $categoryNames = [];
            foreach (DB::table('categories')->where('is_active', true)->get(['id', 'name_ar', 'name_en']) as $item) {
                foreach ([$item->name_ar, $item->name_en] as $name) {
                    $name = trim((string) $name);
                    if ($name === '') {
                        continue;
                    }
                    $categoryNames[$name] = isset($categoryNames[$name]) ? null : (int) $item->id;
                }
            }

            $rows = [];
            $seenSkus = [];
            $seenBarcodes = [];
            $errors = [];
            $line = 1;

            while (($cells = fgetcsv($handle)) !== false) {
                ++$line;
                if (count($cells) === 1 && trim((string) $cells[0]) === '') {
                    continue;
                }
                if ($line > 201) {
                    $errors[] = 'Maximum 200 rows per upload.';
                    break;
                }
                $expectedColumns = $extendedForm ? count(self::EXTENDED_FORM_HEADERS)
                        : ($formTemplate ? count(self::FORM_HEADERS) : count(self::HEADERS));
                if (count($cells) !== $expectedColumns) {
                    $actual = count($cells);
                    $errors[] = "الصف {$line} - الأعمدة: الملف يحتوي على {$actual} أعمدة، والمطلوب {$expectedColumns}. احتفظ بترتيب الأعمدة ولا تحذف أعمدة القالب.";
                    continue;
                }
                $values = array_map('trim', $cells);
                if ($formTemplate) {
                    [$sku, $barcode, $storeValue, $category, $nameAr, $nameEn,
                        $descriptionAr, $descriptionEn, $priceSar, $vatLabel] = array_slice($values, 0, 10);
                    $variantNameAr = $extendedForm ? $values[10] : '';
                    $variantNameEn = $extendedForm ? $values[11] : '';
                    if ($storeValue !== '' && !in_array($storeValue, $validStoreNames, true)) {
                        $expected = trim((string) ($store?->name_ar ?? '')) ?: (string) $sellerId;
                        $errors[] = "الصف {$line} - عمود المتجر: القيمة «{$storeValue}» لا تطابق المتجر المحدد «{$expected}» (رقم {$sellerId}). اترك الخانة فارغة لاستخدام المتجر المحدد تلقائيًا، أو أدخل اسمه/رقمه الصحيح.";
                        continue;
                    }
                    // Avoid float rounding for prices; the UI shows SAR and the API stores halalas.
                    if (!preg_match('/^[0-9]+(?:\\.[0-9]{1,2})?$/D', $priceSar)) {
                        $errors[] = "الصف {$line} - عمود السعر بالريال: القيمة «{$priceSar}» غير صحيحة. أدخل رقمًا من 1 إلى 10 مثل 5.75، بفاصلة عشرية نقطة وبحد أقصى منزلتين.";
                        continue;
                    }
                    $priceParts = explode('.', $priceSar, 2);
                    $priceHalalas = (int) $priceParts[0] * 100
                        + (int) str_pad($priceParts[1] ?? '0', 2, '0', STR_PAD_RIGHT);
                    $vatClass = match ($vatLabel) {
                        'قياسية', 'Standard', 'standard' => 'standard',
                        'صفرية', 'Zero-rated', 'zero' => 'zero',
                        'معفاة', 'Exempt', 'exempt' => 'exempt',
                        default => $vatLabel,
                    };
                    if (!ctype_digit($category)) {
                        if (!isset($categoryNames[$category])) {
                            $errors[] = "الصف {$line} - عمود التصنيف: القيمة «{$category}» غير معروفة أو اسم مكرر. اختر تصنيفًا نشطًا من قائمة القالب أو اكتب رقم التصنيف الصحيح.";
                            continue;
                        }
                        $category = (string) $categoryNames[$category];
                    }
                    $row = [
                        'category_id' => $category,
                        'name_ar' => $nameAr,
                        'name_en' => $nameEn,
                        'description_ar' => $descriptionAr,
                        'description_en' => $descriptionEn,
                        'price_halalas' => (string) $priceHalalas,
                        'vat_class' => $vatClass,
                        'sku' => $sku,
                        'barcode' => $barcode,
                        'variant_name_ar' => $variantNameAr,
                        'variant_name_en' => $variantNameEn,
                    ];
                } else {
                    $row = array_combine(self::HEADERS, $values);
                    $row['description_ar'] = '';
                    $row['description_en'] = '';
                    $row['variant_name_ar'] = '';
                    $row['variant_name_en'] = '';
                }
                $sku = $row['sku'];
                $barcode = $row['barcode'];
                $rowErrors = [];
                if (mb_strlen($row['variant_name_ar']) > 180) $rowErrors[] = 'اسم وحدة البيع بالعربية: الحد الأقصى 180 حرفًا';
                if (mb_strlen($row['variant_name_en']) > 180) $rowErrors[] = 'اسم وحدة البيع بالإنجليزية: الحد الأقصى 180 حرفًا';
                if ($sku === '') $rowErrors[] = 'رمز SKU: مطلوب؛ أدخل رمزًا فريدًا للمنتج';
                if ($row['category_id'] === '' || !ctype_digit($row['category_id']))
                    $rowErrors[] = 'التصنيف: أدخل رقم تصنيف نشط أو اسمه الصحيح';
                if ($row['name_ar'] === '') $rowErrors[] = 'الاسم بالعربية: مطلوب';
                if ($row['name_en'] === '') $rowErrors[] = 'الاسم بالإنجليزية: مطلوب';
                if ($row['price_halalas'] === '' || !ctype_digit($row['price_halalas']) ||
                    (int) $row['price_halalas'] < 100 || (int) $row['price_halalas'] > 1000)
                    $rowErrors[] = 'السعر: يجب أن يكون من 1 إلى 10 ريال';
                if (!in_array($row['vat_class'], array_column(VatClass::cases(), 'value'), true))
                    $rowErrors[] = 'فئة الضريبة: القيمة «'.$row['vat_class'].'» غير صحيحة؛ اختر قياسية أو صفرية أو معفاة';
                if ($rowErrors !== []) {
                    foreach ($rowErrors as $problem) {
                        $errors[] = "الصف {$line} - {$problem}.";
                    }
                    continue;
                }
                if (!ctype_digit($row['category_id']) || !ctype_digit($row['price_halalas'])
                    || (int) $row['price_halalas'] < 100 || (int) $row['price_halalas'] > 1000
                    || !in_array($row['vat_class'], array_column(VatClass::cases(), 'value'), true)
                    || $sku === '' || strlen($sku) > 100
                    || $barcode !== '' && strlen($barcode) > 100
                    || $row['name_ar'] === '' || $row['name_en'] === ''
                    || mb_strlen($row['name_ar']) > 180 || mb_strlen($row['name_en']) > 180
                    || mb_strlen($row['description_ar']) > 10000 || mb_strlen($row['description_en']) > 10000) {
                    $errors[] = "الصف {$line}: تحقق من طول اسم المنتج (حتى 180 حرفًا)، والوصف (حتى 10000 حرف)، ورمز SKU أو الباركود (حتى 100 حرف).";
                    continue;
                }
                if (isset($seenSkus[$sku]) || ($barcode !== '' && isset($seenBarcodes[$barcode]))) {
                    $errors[] = "الصف {$line} - رمز SKU أو الباركود: قيمة مكررة داخل الملف (SKU: {$sku}). يجب أن يكون كل SKU والباركود فريدين.";
                    continue;
                }
                $seenSkus[$sku] = true;
                if ($barcode !== '') {
                    $seenBarcodes[$barcode] = true;
                }
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
            if ($converted !== null) @unlink($converted);
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'لا يحتوي الملف على أي منتجات. أدخل منتجًا واحدًا على الأقل بعد صف العناوين.';
        }

        $categoryIds = array_unique(array_map(static fn (array $row): int => (int) $row['category_id'], $rows));
        $validCategories = DB::table('categories')->where('is_active', true)->whereIn('id', $categoryIds)->pluck('id')->all();
        if (count($validCategories) !== count($categoryIds)) {
            $invalidIds = array_diff($categoryIds, $validCategories);
            foreach ($rows as $index => $row) {
                if (in_array((int) $row['category_id'], $invalidIds, true)) {
                    $errors[] = 'الصف '.($index + 2).' - التصنيف: الرقم '.$row['category_id'].' غير موجود أو غير نشط.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 20)]);
        }

        // Read-only preview uses the same validation as the real import.
        // It checks existing SKUs/barcodes but never creates a draft.
        if ($request->boolean('dry_run')) {
            $skus = array_column($rows, 'sku');
            $barcodes = array_values(array_filter(array_column($rows, 'barcode'), static fn ($v) => $v !== ''));
            if (DB::table('product_variants')->whereIn('sku', $skus)
                ->orWhereIn('barcode', $barcodes)->exists()) {
                throw ValidationException::withMessages(['file' => ['One or more SKUs/barcodes already exist.']]);
            }

            return response()->json(['data' => [
                'valid_rows' => count($rows),
                'preview' => array_map(static fn (array $row): array => [
                    'category_id' => (int) $row['category_id'],
                    'name_ar' => $row['name_ar'],
                    'name_en' => $row['name_en'],
                    'description_ar' => $row['description_ar'] ?: null,
                    'description_en' => $row['description_en'] ?: null,
                    'price_halalas' => (int) $row['price_halalas'],
                    'sku' => $row['sku'],
                ], array_slice($rows, 0, 10)),
                'created_drafts' => 0,
            ]]);
        }

        $created = DB::transaction(function () use ($rows, $sellerId): int {
            $skus = array_column($rows, 'sku');
            $barcodes = array_values(array_filter(array_column($rows, 'barcode'), static fn ($v) => $v !== ''));
            if (DB::table('product_variants')->whereIn('sku', $skus)
                ->orWhereIn('barcode', $barcodes)->exists()) {
                throw ValidationException::withMessages(['file' => ['One or more SKUs/barcodes already exist.']]);
            }

            foreach ($rows as $row) {
                $product = Product::query()->create([
                    'seller_id' => $sellerId,
                    'category_id' => (int) $row['category_id'],
                    'slug' => (string) Str::uuid(),
                    'name_ar' => $row['name_ar'],
                    'name_en' => $row['name_en'],
                    'price_halalas' => (int) $row['price_halalas'],
                    'vat_class' => $row['vat_class'],
                    'status' => ProductStatus::Draft,
                    'is_featured' => false,
                    'rating_average' => 0,
                    'rating_count' => 0,
                ]);
                $product->variants()->create([
                    'sku' => $row['sku'],
                    'barcode' => $row['barcode'] ?: null,
                    'name_ar' => $row['variant_name_ar'] ?: null,
                    'name_en' => $row['variant_name_en'] ?: null,
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
            }
            return count($rows);
        }, 3);

        return response()->json(['data' => ['created_drafts' => $created]], 201);
    }
}
