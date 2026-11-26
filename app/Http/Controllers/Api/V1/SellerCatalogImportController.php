<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Pricing\VatClass;
use App\Http\Controllers\Controller;
use App\Support\SellerDemoMode;
use App\Support\SellerProductImageSource;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Bounded UTF-8 CSV import. Validate the whole batch before writing any product.
 * Imports drafts or submits them under the same seller publication safeguards.
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
        'الوصف بالإنجليزية (اختياري)', 'السعر بالريال (1–100) (إجباري)',
        'فئة الضريبة (إجباري)',
    ];

    // Optional selling-unit labels correspond to the variant fields in the Add Product workflow.
    private const EXTENDED_FORM_HEADERS = [
        'رمز SKU (إجباري)', 'الباركود (اختياري)', 'المتجر (اختياري)',
        'التصنيف (إجباري)', 'اسم المنتج بالعربية (إجباري)',
        'اسم المنتج بالإنجليزية (إجباري)', 'الوصف بالعربية (اختياري)',
        'الوصف بالإنجليزية (اختياري)', 'السعر بالريال (1–100) (إجباري)',
        'فئة الضريبة (إجباري)', 'اسم وحدة البيع بالعربية (اختياري)',
        'اسم وحدة البيع بالإنجليزية (اختياري)',
    ];

    private const EXTENDED_ENGLISH_FORM_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store (optional)', 'Category (required)',
        'Arabic product name (required)', 'English product name (required)',
        'Arabic description (optional)', 'English description (optional)',
        'Price SAR (1–100) (required)', 'VAT class (required)',
        'Arabic variant name (optional)', 'English variant name (optional)',
    ];

    private const IMAGE_FORM_HEADERS = [
        'رمز SKU (إجباري)', 'الباركود (اختياري)', 'المتجر (اختياري)',
        'التصنيف (إجباري)', 'اسم المنتج بالعربية (إجباري)',
        'اسم المنتج بالإنجليزية (إجباري)', 'الوصف بالعربية (اختياري)',
        'الوصف بالإنجليزية (اختياري)', 'السعر بالريال (1–100) (إجباري)',
        'فئة الضريبة (إجباري)', 'اسم وحدة البيع بالعربية (اختياري)',
        'اسم وحدة البيع بالإنجليزية (اختياري)',
        'رابط صورة المنتج HTTPS (اختياري)', 'صورة داخل Excel (اختياري)',
    ];

    private const VAT_IMAGE_FORM_HEADERS = [
        ...self::IMAGE_FORM_HEADERS, 'السبب النظامي للضريبة (إجباري للصفرية والمعفاة)',
    ];

    private const STOCK_FORM_HEADERS = [
        ...self::VAT_IMAGE_FORM_HEADERS,
        'الفرع للمخزون (إجباري عند إدخال كمية)', 'الكمية في المخزون (اختياري)',
    ];

    private const STOCK_ENGLISH_HEADERS = [
        ...self::VAT_IMAGE_ENGLISH_HEADERS,
        'Inventory branch (required when quantity entered)', 'Inventory quantity (optional)',
    ];

    private const VAT_IMAGE_ENGLISH_HEADERS = [
        ...self::IMAGE_ENGLISH_HEADERS, 'Legal VAT justification (required for zero-rated/exempt)',
    ];

    private const IMAGE_ENGLISH_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store (optional)', 'Category (required)',
        'Arabic product name (required)', 'English product name (required)',
        'Arabic description (optional)', 'English description (optional)',
        'Price SAR (1–100) (required)', 'VAT class (required)',
        'Arabic variant name (optional)', 'English variant name (optional)',
        'HTTPS image URL (optional)', 'Embedded Excel image (optional)',
    ];

    // Prior Arabic layout (same values, earlier captions) remains supported.
    private const PREVIOUS_FORM_HEADERS = [
        'رمز SKU (مطلوب)', 'الباركود (اختياري)', 'المتجر', 'التصنيف',
        'اسم المنتج بالعربية', 'اسم المنتج بالإنجليزية', 'الوصف بالعربية',
        'الوصف بالإنجليزية', 'السعر بالريال (1–100)', 'فئة الضريبة',
    ];

    private const LEGACY_FORM_HEADERS = [
        'رمز SKU (مطلوب)', 'الباركود (اختياري)', 'المتجر', 'التصنيف',
        'اسم المنتج بالعربية', 'اسم المنتج بالإنجليزية', 'الوصف بالعربية',
        'الوصف بالإنجليزية', 'السعر بالريال (1–100)', 'فئة الضريبة',
    ];

    private const LEGACY_ENGLISH_FORM_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store', 'Category',
        'Arabic product name', 'English product name', 'Arabic description',
        'English description', 'Price SAR (1–100)', 'VAT class',
    ];

    private const ENGLISH_FORM_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store (optional)', 'Category (required)',
        'Arabic product name (required)', 'English product name (required)',
        'Arabic description (optional)', 'English description (optional)',
        'Price SAR (1–100) (required)', 'VAT class (required)',
    ];

    private const PREVIOUS_ENGLISH_FORM_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store', 'Category',
        'Arabic product name', 'English product name', 'Arabic description',
        'English description', 'Price SAR (1–100)', 'VAT class',
    ];

    /** @var array<int, string> Excel row number to JPEG/PNG/WebP bytes. */
    private array $embeddedImages = [];

    /**
     * Read floating pictures placed over image column N, anchored to a data row.
     * Excel's "Pictures > Place over Cells" workflow is supported. Excel 365
     * IMAGE() formulas and rich-data in-cell images are not supported.
     */
    private function readEmbeddedImages(ZipArchive $zip): array
    {
        $drawing = $zip->getFromName('xl/drawings/drawing1.xml');
        $relations = $zip->getFromName('xl/drawings/_rels/drawing1.xml.rels');
        if ($drawing === false || $relations === false ||
            strlen($drawing) > 1024 * 1024 || strlen($relations) > 1024 * 1024) {
            return [];
        }

        $drawingDoc = new DOMDocument;
        $relsDoc = new DOMDocument;
        if (! @$drawingDoc->loadXML($drawing, LIBXML_NONET) ||
            ! @$relsDoc->loadXML($relations, LIBXML_NONET)) {
            throw ValidationException::withMessages(['file' => ['Invalid Excel image definitions.']]);
        }
        $relXp = new DOMXPath($relsDoc);
        $targets = [];
        foreach ($relXp->query('//*[local-name()="Relationship"]') as $rel) {
            $id = $rel->attributes?->getNamedItem('Id')?->nodeValue;
            $target = $rel->attributes?->getNamedItem('Target')?->nodeValue;
            $mode = $rel->attributes?->getNamedItem('TargetMode')?->nodeValue;
            if (! is_string($id) || ! is_string($target) || $mode === 'External') {
                continue;
            }
            $name = basename(str_replace('\\', '/', $target));
            if (preg_match('/^[\w.-]+\.(png|jpe?g|webp)$/iD', $name)) {
                $targets[$id] = 'xl/media/'.$name;
            }
        }

        $xp = new DOMXPath($drawingDoc);
        $images = [];
        $totalBytes = 0;
        foreach ($xp->query('/*[local-name()="wsDr"]/*[local-name()="oneCellAnchor" or local-name()="twoCellAnchor"]') as $anchor) {
            $col = $xp->query('./*[local-name()="from"]/*[local-name()="col"]', $anchor)->item(0)?->textContent;
            $row = $xp->query('./*[local-name()="from"]/*[local-name()="row"]', $anchor)->item(0)?->textContent;
            if ($col === null || $row === null || (int) $col !== 13) {
                continue;
            }
            $sheetRow = (int) $row + 1;
            if ($sheetRow < 2 || $sheetRow > 201) {
                continue;
            }
            $blip = $xp->query('.//*[local-name()="blip"]', $anchor)->item(0);
            if ($blip === null) {
                continue;
            }
            $id = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
            $name = $targets[$id] ?? null;
            if ($name === null || isset($images[$sheetRow])) {
                throw ValidationException::withMessages(['file' => ["Row {$sheetRow}: unsupported or duplicate embedded image."]]);
            }
            $metadata = $zip->statName($name);
            if ($metadata === false || ($metadata['size'] ?? 0) > 5 * 1024 * 1024) {
                throw ValidationException::withMessages(['file' => ["Row {$sheetRow}: image is missing or exceeds 5 MB."]]);
            }
            $bytes = $zip->getFromName($name);
            if ($bytes === false || strlen($bytes) > 5 * 1024 * 1024) {
                throw ValidationException::withMessages(['file' => ["Row {$sheetRow}: image is missing or exceeds 5 MB."]]);
            }
            $totalBytes += strlen($bytes);
            if ($totalBytes > 20 * 1024 * 1024) {
                throw ValidationException::withMessages(['file' => ['Embedded images exceed the 20 MB total limit.']]);
            }
            $images[$sheetRow] = $bytes;
        }

        return $images;
    }

    /**
     * Read the first Excel worksheet into a temporary CSV stream. Restrict the
     * input to the fourteen visible product columns; ignore the hidden dropdown lists.
     * Any Excel formula is rejected rather than executed or interpreted.
     */
    private function xlsxAsCsv(string $file): string
    {
        $zip = new ZipArchive;
        if ($zip->open($file) !== true) {
            throw ValidationException::withMessages(['file' => ['Excel file is invalid or corrupt.']]);
        }
        try {
            $this->embeddedImages = $this->readEmbeddedImages($zip);
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
                $shared = new DOMDocument;
                if (! @$shared->loadXML($sharedXml, LIBXML_NONET)) {
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
            $document = new DOMDocument;
            if (! @$document->loadXML($sheet, LIBXML_NONET)) {
                throw ValidationException::withMessages(['file' => ['Invalid Excel worksheet.']]);
            }
            $xp = new DOMXPath($document);
            $temp = tempnam(sys_get_temp_dir(), 'seller-xlsx-csv-');
            if ($temp === false) {
                abort(500);
            }
            $handle = fopen($temp, 'wb');
            if ($handle === false) {
                @unlink($temp);
                abort(500);
            }
            try {
                $seen = 0;
                $columnCount = 14;
                foreach ($xp->query('/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                    $number = (int) $row->attributes?->getNamedItem('r')?->nodeValue;
                    if ($number < 1) {
                        continue;
                    }
                    $cells = array_fill(0, 17, '');
                    $hasProductCell = false;
                    foreach ($xp->query('./*[local-name()="c"]', $row) as $cell) {
                        $reference = (string) $cell->attributes?->getNamedItem('r')?->nodeValue;
                        if (! preg_match('/^([A-Z]+)[0-9]+$/', $reference, $m)) {
                            continue;
                        }
                        $column = $m[1];
                        if (strlen($column) !== 1 || $column < 'A' || $column > 'Q') {
                            continue;
                        }
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
                    if (! $hasProductCell) {
                        continue;
                    }
                    if ($number === 1) {
                        $columnCount = $cells[16] !== '' ? 17 : ($cells[14] !== '' ? 15 : 14);
                    }
                    if ($number > 201 || ++$seen > 201) {
                        throw ValidationException::withMessages(['file' => ['Maximum 200 product rows per upload.']]);
                    }
                    fputcsv($handle, array_slice($cells, 0, $columnCount), escape: '\\');
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
            'submit_after_import' => ['sometimes', 'boolean'],
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx'],
        ]);

        $sellerId = (int) $input['seller_id'];
        $submitAfterImport = $request->boolean('submit_after_import') && ! $request->boolean('dry_run');
        $directPublishEnabled = $submitAfterImport &&
            (config('store.seller_direct_publish_enabled') === true ||
                SellerDemoMode::allows($request, $sellerId));
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
            if ($converted !== null) {
                @unlink($converted);
            }
            throw ValidationException::withMessages(['file' => ['Unable to read upload.']]);
        }

        try {
            if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            $header = fgetcsv($handle, escape: '\\');
            if ($header === false) {
                throw ValidationException::withMessages(['file' => ['CSV is empty.']]);
            }
            // Accept older downloaded templates while enforcing the current price range.
            // Compare the normalized caption only; price values are checked separately.
            if (isset($header[8])) {
                $header[8] = str_replace(['(1–10)', '(1–5.75)'], '(1–100)', $header[8]);
            }
            // Friendly localized headers are converted to the internal schema by position.
            // Existing CSV files with technical headers remain fully supported.
            $stockForm = in_array($header, [
                self::STOCK_FORM_HEADERS, self::STOCK_ENGLISH_HEADERS,
            ], true);
            $vatImageForm = $stockForm || in_array($header, [
                self::VAT_IMAGE_FORM_HEADERS, self::VAT_IMAGE_ENGLISH_HEADERS,
            ], true);
            $imageForm = $vatImageForm || in_array($header, [
                self::IMAGE_FORM_HEADERS, self::IMAGE_ENGLISH_HEADERS,
            ], true);
            $extendedForm = $imageForm || in_array($header, [
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

            while (($cells = fgetcsv($handle, escape: '\\')) !== false) {
                $line++;
                if (count($cells) === 1 && trim((string) $cells[0]) === '') {
                    continue;
                }
                if ($line > 201) {
                    $errors[] = 'Maximum 200 rows per upload.';
                    break;
                }
                $expectedColumns = $stockForm ? count(self::STOCK_FORM_HEADERS)
                    : ($vatImageForm ? count(self::VAT_IMAGE_FORM_HEADERS)
                        : ($imageForm ? count(self::IMAGE_FORM_HEADERS)
                        : ($extendedForm ? count(self::EXTENDED_FORM_HEADERS)
                        : ($formTemplate ? count(self::FORM_HEADERS) : count(self::HEADERS)))));
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
                    $imageUrl = $imageForm ? $values[12] : '';
                    $embeddedHint = $imageForm ? $values[13] : '';
                    $vatJustification = $vatImageForm ? $values[14] : '';
                    $inventoryBranch = $stockForm ? $values[15] : '';
                    $inventoryQuantity = $stockForm ? $values[16] : '';
                    if ($embeddedHint !== '' && ! $isExcel) {
                        $errors[] = "الصف {$line} - صورة داخل Excel: لا يمكن تضمين الصور في CSV؛ استخدم رابط HTTPS في عمود رابط الصورة أو ملف Excel.";

                        continue;
                    }
                    if ($imageUrl !== '' && (! filter_var($imageUrl, FILTER_VALIDATE_URL) ||
                        ! str_starts_with($imageUrl, 'https://') || strlen($imageUrl) > 2048)) {
                        $errors[] = "الصف {$line} - رابط الصورة: أدخل رابط HTTPS صالحًا أو اتركه فارغًا.";

                        continue;
                    }
                    if ($storeValue !== '' && ! in_array($storeValue, $validStoreNames, true)) {
                        $expected = trim((string) ($store?->name_ar ?? '')) ?: (string) $sellerId;
                        $errors[] = "الصف {$line} - عمود المتجر: القيمة «{$storeValue}» لا تطابق المتجر المحدد «{$expected}» (رقم {$sellerId}). اترك الخانة فارغة لاستخدام المتجر المحدد تلقائيًا، أو أدخل اسمه/رقمه الصحيح.";

                        continue;
                    }
                    // Avoid float rounding for prices; the UI shows SAR and the API stores halalas.
                    if (! preg_match('/^[0-9]+(?:\\.[0-9]{1,2})?$/D', $priceSar)) {
                        $errors[] = "الصف {$line} - عمود السعر بالريال: القيمة «{$priceSar}» غير صحيحة. أدخل رقمًا من 1 إلى 100 مثل 5.75، بفاصلة عشرية نقطة وبحد أقصى منزلتين.";

                        continue;
                    }
                    $priceParts = explode('.', $priceSar, 2);
                    $priceHalalas = (int) $priceParts[0] * 100
                        + (int) str_pad($priceParts[1] ?? '0', 2, '0', STR_PAD_RIGHT);
                    $vatClass = match ($vatLabel) {
                        'قياسية', 'قياسية (15%)', 'Standard', 'Standard VAT (15%)', 'standard' => 'standard',
                        'صفرية', 'صفرية (0%)', 'Zero-rated', 'Zero-rated VAT (0%)', 'zero' => 'zero',
                        'معفاة', 'معفى من الضريبة', 'Exempt', 'VAT exempt', 'exempt' => 'exempt',
                        default => $vatLabel,
                    };
                    if (! ctype_digit($category)) {
                        if (! isset($categoryNames[$category])) {
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
                        'vat_justification' => $vatJustification,
                        'sku' => $sku,
                        'barcode' => $barcode,
                        'variant_name_ar' => $variantNameAr,
                        'variant_name_en' => $variantNameEn,
                        'image_url' => $imageUrl,
                        'image_bytes' => $isExcel ? ($this->embeddedImages[$line] ?? null) : null,
                        'inventory_branch_id' => $inventoryBranch,
                        'inventory_quantity' => $inventoryQuantity,
                    ];
                } else {
                    $row = array_combine(self::HEADERS, $values);
                    $row['vat_justification'] = '';
                    $row['description_ar'] = '';
                    $row['description_en'] = '';
                    $row['variant_name_ar'] = '';
                    $row['variant_name_en'] = '';
                    $row['image_url'] = '';
                    $row['image_bytes'] = null;
                    $row['inventory_branch_id'] = '';
                    $row['inventory_quantity'] = '';
                }
                $sku = $row['sku'];
                $barcode = $row['barcode'];
                $rowErrors = [];
                if ($imageForm && $isExcel && $embeddedHint !== '' && $row['image_bytes'] === null) {
                    $rowErrors[] = 'صورة Excel: العمود مخصص للصورة داخل الخلية؛ استخدم Insert > Pictures > Place over Cells وحدد موضعها في العمود N بنفس الصف';
                }
                if ($row['image_url'] !== '' && $row['image_bytes'] !== null) {
                    $rowErrors[] = 'الصورة: استخدم رابط الصورة أو الصورة المضمنة، وليس كليهما';
                }
                if (mb_strlen($row['variant_name_ar']) > 180) {
                    $rowErrors[] = 'اسم وحدة البيع بالعربية: الحد الأقصى 180 حرفًا';
                }
                if (mb_strlen($row['variant_name_en']) > 180) {
                    $rowErrors[] = 'اسم وحدة البيع بالإنجليزية: الحد الأقصى 180 حرفًا';
                }
                if ($row['inventory_quantity'] !== '') {
                    if (! ctype_digit($row['inventory_quantity']) ||
                        (int) $row['inventory_quantity'] > 100000000) {
                        $rowErrors[] = 'الكمية في المخزون: أدخل عددًا صحيحًا من 0 إلى 100000000';
                    }
                    if ($row['inventory_branch_id'] === '' || ! ctype_digit($row['inventory_branch_id']) ||
                        (int) $row['inventory_branch_id'] < 1) {
                        $rowErrors[] = 'الفرع للمخزون: اختر رقم فرع صحيحًا عند إدخال كمية';
                    }
                } elseif ($row['inventory_branch_id'] !== '' &&
                    (! ctype_digit($row['inventory_branch_id']) || (int) $row['inventory_branch_id'] < 1)) {
                    $rowErrors[] = 'الفرع للمخزون: رقم الفرع غير صحيح';
                }
                if ($sku === '') {
                    $rowErrors[] = 'رمز SKU: مطلوب؛ أدخل رمزًا فريدًا للمنتج';
                }
                if ($row['category_id'] === '' || ! ctype_digit($row['category_id'])) {
                    $rowErrors[] = 'التصنيف: أدخل رقم تصنيف نشط أو اسمه الصحيح';
                }
                if ($row['name_ar'] === '') {
                    $rowErrors[] = 'الاسم بالعربية: مطلوب';
                }
                if ($row['name_en'] === '') {
                    $rowErrors[] = 'الاسم بالإنجليزية: مطلوب';
                }
                if ($row['price_halalas'] === '' || ! ctype_digit($row['price_halalas']) ||
                    (int) $row['price_halalas'] < 100 || (int) $row['price_halalas'] > 10000) {
                    $rowErrors[] = 'السعر: يجب أن يكون من 1 إلى 100 ريال';
                }
                if (! in_array($row['vat_class'], array_column(VatClass::cases(), 'value'), true)) {
                    $rowErrors[] = 'فئة الضريبة: القيمة «'.$row['vat_class'].'» غير صحيحة؛ اختر قياسية أو صفرية أو معفاة';
                }
                $justification = trim((string) $row['vat_justification']);
                if (in_array($row['vat_class'], ['zero', 'exempt'], true) &&
                    (mb_strlen($justification) < 15 || mb_strlen($justification) > 500)) {
                    $rowErrors[] = 'السبب النظامي: للصفرية أو المعفاة اكتب سببًا نظاميًا واضحًا من 15 إلى 500 حرف في العمود O';
                }
                if ($row['vat_class'] === 'standard' && $justification !== '') {
                    $rowErrors[] = 'السبب النظامي: اتركه فارغًا للضريبة القياسية';
                }
                if ($rowErrors !== []) {
                    foreach ($rowErrors as $problem) {
                        $errors[] = "الصف {$line} - {$problem}.";
                    }

                    continue;
                }
                if (! ctype_digit($row['category_id']) || ! ctype_digit($row['price_halalas'])
                    || (int) $row['price_halalas'] < 100 || (int) $row['price_halalas'] > 10000
                    || ! in_array($row['vat_class'], array_column(VatClass::cases(), 'value'), true)
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
            if ($converted !== null) {
                @unlink($converted);
            }
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

        // Stock can only be inserted for an active branch of this seller that this user manages.
        $branchIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['inventory_branch_id'],
            array_filter($rows, static fn (array $row): bool => $row['inventory_quantity'] !== ''),
        )));
        if ($branchIds !== []) {
            $authorized = DB::table('branches')
                ->whereIn('id', $branchIds)
                ->where('seller_id', $sellerId)
                ->where('is_active', true)
                ->whereExists(function ($query) use ($request): void {
                    $query->selectRaw('1')->from('seller_staff_access')
                        ->whereColumn('seller_staff_access.seller_id', 'branches.seller_id')
                        ->where('seller_staff_access.user_id', $request->user()->id)
                        ->where('seller_staff_access.is_active', true)
                        ->whereIn('seller_staff_access.role', ['owner', 'branch_manager'])
                        ->where(function ($scope): void {
                            $scope->whereNull('seller_staff_access.branch_id')
                                ->orWhereColumn('seller_staff_access.branch_id', 'branches.id');
                        });
                })
                ->pluck('id')->map(fn ($id): int => (int) $id)->all();
            foreach ($rows as $index => $row) {
                if ($row['inventory_quantity'] !== '' &&
                    ! in_array((int) $row['inventory_branch_id'], $authorized, true)) {
                    $errors[] = 'الصف '.($index + 2).' - الفرع للمخزون: الفرع غير نشط أو لا يتبع المتجر أو ليس لديك صلاحية عليه.';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 20)]);
        }

        $skus = array_column($rows, 'sku');
        $barcodes = array_values(array_filter(array_column($rows, 'barcode'), static fn ($v) => $v !== ''));
        $duplicatesExist = static fn (): bool => DB::table('product_variants')->whereIn('sku', $skus)
            ->orWhereIn('barcode', $barcodes)->exists();
        $rejectDuplicates = static function () use ($duplicatesExist): void {
            if ($duplicatesExist()) {
                throw ValidationException::withMessages(['file' => ['One or more SKUs/barcodes already exist.']]);
            }
        };

        // Preview is read-only. Check again under the seller lock before saving.
        $rejectDuplicates();
        if ($request->boolean('dry_run')) {

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
                    'inventory_branch_id' => $row['inventory_branch_id'] !== '' ? (int) $row['inventory_branch_id'] : null,
                    'inventory_quantity' => $row['inventory_quantity'] !== '' ? (int) $row['inventory_quantity'] : null,
                ], array_slice($rows, 0, 10)),
                'created_drafts' => 0,
            ]]);
        }

        // Download and validate images before opening the database transaction.
        // Clean up every stored file if any image or DB operation fails.
        $storedImages = [];
        try {
            foreach ($rows as $index => $row) {
                try {
                    if ($row['image_bytes'] !== null) {
                        $storedImages[$index] = SellerProductImageSource::storeBytes($row['image_bytes'], $sellerId);
                    } elseif ($row['image_url'] !== '') {
                        $storedImages[$index] = SellerProductImageSource::storeUrl($row['image_url'], $sellerId);
                    }
                } catch (ValidationException $exception) {
                    $messages = collect($exception->errors())->flatten()->all();
                    throw ValidationException::withMessages(['file' => [
                        'Excel row '.($index + 2).' (SKU '.$row['sku'].'): '.implode(' ', $messages),
                    ]]);
                }
            }

            // Publishing requires an actual local cover image; all rows have already
            // passed category, VAT/legal-basis, SKU and variant validation.
            // Reject the entire batch rather than publishing only some products.
            if ($directPublishEnabled) {
                foreach ($rows as $index => $row) {
                    $imagePath = $storedImages[$index] ?? null;
                    if (! $imagePath || ! Storage::disk('public')->exists($imagePath)) {
                        throw ValidationException::withMessages(['file' => [
                            'Row '.($index + 2).': A valid product cover image is required for direct publication.',
                        ]]);
                    }
                }
            }

            $result = DB::transaction(function () use ($rows, $sellerId, $storedImages, $submitAfterImport, $directPublishEnabled, $rejectDuplicates): array {
                // Lock before reading duplicates. SQLite needs a write as the first statement
                // to avoid a lock upgrade race. The no-op update leaves the timestamp intact.
                DB::table('sellers')->where('id', $sellerId)->update(['updated_at' => DB::raw('updated_at')]);
                $rejectDuplicates();

                $published = 0;
                $submitted = 0;
                $stockRecordsCreated = 0;
                foreach ($rows as $index => $row) {
                    $directPublish = $directPublishEnabled;
                    $status = ! $submitAfterImport ? ProductStatus::Draft
                        : ($directPublish ? ProductStatus::Published : ProductStatus::PendingReview);
                    if ($directPublish) {
                        $published++;
                    } elseif ($submitAfterImport) {
                        $submitted++;
                    }
                    $product = Product::query()->create([
                        'seller_id' => $sellerId,
                        'category_id' => (int) $row['category_id'],
                        'slug' => (string) Str::uuid(),
                        'name_ar' => $row['name_ar'],
                        'name_en' => $row['name_en'],
                        'description_ar' => $row['description_ar'] !== '' ? $row['description_ar'] : null,
                        'description_en' => $row['description_en'] !== '' ? $row['description_en'] : null,
                        'image_path' => $storedImages[$index] ?? null,
                        'price_halalas' => (int) $row['price_halalas'],
                        'vat_class' => $row['vat_class'],
                        'vat_justification' => $row['vat_class'] === 'standard' ? null : $row['vat_justification'],
                        'status' => $status,
                        'is_featured' => false,
                        'rating_average' => 0,
                        'rating_count' => 0,
                    ]);
                    $variant = $product->variants()->create([
                        'sku' => $row['sku'],
                        'barcode' => $row['barcode'] ?: null,
                        'name_ar' => $row['variant_name_ar'] ?: null,
                        'name_en' => $row['variant_name_en'] ?: null,
                        'is_active' => true,
                        'sort_order' => 0,
                    ]);
                    if ($row['inventory_quantity'] !== '') {
                        DB::table('branch_stock')->insert([
                            'branch_id' => (int) $row['inventory_branch_id'],
                            'variant_id' => $variant->id,
                            'on_hand' => (int) $row['inventory_quantity'],
                            'reserved' => 0,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $stockRecordsCreated++;
                    }
                }

                return [
                    'created_drafts' => $submitAfterImport ? 0 : count($rows),
                    'published' => $published,
                    'submitted_for_review' => $submitted,
                    'stock_records_created' => $stockRecordsCreated,
                ];
            }, 3);
        } catch (\Throwable $exception) {
            if ($storedImages !== []) {
                Storage::disk('public')->delete(array_values($storedImages));
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                $rejectDuplicates();
            }
            throw $exception;
        }

        return response()->json(['data' => $result], 201);
    }
}
