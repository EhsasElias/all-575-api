<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Pricing\VatClass;
use App\Http\Controllers\Controller;
use App\Support\SellerProductImageSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
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
    private const IMAGE_FORM_HEADERS = [
        'رمز SKU (إجباري)', 'الباركود (اختياري)', 'المتجر (اختياري)',
        'التصنيف (إجباري)', 'اسم المنتج بالعربية (إجباري)',
        'اسم المنتج بالإنجليزية (إجباري)', 'الوصف بالعربية (اختياري)',
        'الوصف بالإنجليزية (اختياري)', 'السعر بالريال (1–10) (إجباري)',
        'فئة الضريبة (إجباري)', 'اسم وحدة البيع بالعربية (اختياري)',
        'اسم وحدة البيع بالإنجليزية (اختياري)',
        'رابط صورة المنتج HTTPS (اختياري)', 'صورة داخل Excel (اختياري)',
    ];
    private const VAT_IMAGE_FORM_HEADERS = [
        ...self::IMAGE_FORM_HEADERS, 'السبب النظامي للضريبة (إجباري للصفرية والمعفاة)',
    ];
    private const VAT_IMAGE_ENGLISH_HEADERS = [
        ...self::IMAGE_ENGLISH_HEADERS, 'Legal VAT justification (required for zero-rated/exempt)',
    ];
    private const IMAGE_ENGLISH_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store (optional)', 'Category (required)',
        'Arabic product name (required)', 'English product name (required)',
        'Arabic description (optional)', 'English description (optional)',
        'Price SAR (1–10) (required)', 'VAT class (required)',
        'Arabic variant name (optional)', 'English variant name (optional)',
        'HTTPS image URL (optional)', 'Embedded Excel image (optional)',
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

        $drawingDoc = new DOMDocument();
        $relsDoc = new DOMDocument();
        if (!@$drawingDoc->loadXML($drawing, LIBXML_NONET) ||
            !@$relsDoc->loadXML($relations, LIBXML_NONET)) {
            throw ValidationException::withMessages(['file' => ['Invalid Excel image definitions.']]);
        }
        $relXp = new DOMXPath($relsDoc);
        $targets = [];
        foreach ($relXp->query('//*[local-name()="Relationship"]') as $rel) {
            $id = $rel->attributes?->getNamedItem('Id')?->nodeValue;
            $target = $rel->attributes?->getNamedItem('Target')?->nodeValue;
            $mode = $rel->attributes?->getNamedItem('TargetMode')?->nodeValue;
            if (!is_string($id) || !is_string($target) || $mode === 'External') continue;
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
            if ($col === null || $row === null || (int) $col !== 13) continue;
            $sheetRow = (int) $row + 1;
            if ($sheetRow < 2 || $sheetRow > 201) continue;
            $blip = $xp->query('.//*[local-name()="blip"]', $anchor)->item(0);
            if ($blip === null) continue;
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
        $zip = new ZipArchive();
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
                    $cells = array_fill(0, 15, '');
                    $hasProductCell = false;
                    foreach ($xp->query('./*[local-name()="c"]', $row) as $cell) {
                        $reference = (string) $cell->attributes?->getNamedItem('r')?->nodeValue;
                        if (!preg_match('/^([A-Z]+)[0-9]+$/', $reference, $m)) continue;
                        $column = $m[1];
                        if (strlen($column) !== 1 || $column < 'A' || $column > 'O') continue;
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
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx'],
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
            $vatImageForm = in_array($header, [
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

            while (($cells = fgetcsv($handle)) !== false) {
                ++$line;
                if (count($cells) === 1 && trim((string) $cells[0]) === '') {
                    continue;
                }
                if ($line > 201) {
                    $errors[] = 'Maximum 200 rows per upload.';
                    break;
                }
                $expectedColumns = $vatImageForm ? count(self::VAT_IMAGE_FORM_HEADERS)
                        : ($imageForm ? count(self::IMAGE_FORM_HEADERS)
                        : ($extendedForm ? count(self::EXTENDED_FORM_HEADERS)
                        : ($formTemplate ? count(self::FORM_HEADERS) : count(self::HEADERS))));
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
                    if ($embeddedHint !== '' && !$isExcel) {
                        $errors[] = "الصف {$line} - صورة داخل Excel: لا يمكن تضمين الصور في CSV؛ استخدم رابط HTTPS في عمود رابط الصورة أو ملف Excel.";
                        continue;
                    }
                    if ($imageUrl !== '' && (!filter_var($imageUrl, FILTER_VALIDATE_URL) ||
                        !str_starts_with($imageUrl, 'https://') || strlen($imageUrl) > 2048)) {
                        $errors[] = "الصف {$line} - رابط الصورة: أدخل رابط HTTPS صالحًا أو اتركه فارغًا.";
                        continue;
                    }
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
                        'قياسية', 'قياسية (15%)', 'Standard', 'Standard VAT (15%)', 'standard' => 'standard',
                        'صفرية', 'صفرية (0%)', 'Zero-rated', 'Zero-rated VAT (0%)', 'zero' => 'zero',
                        'معفاة', 'معفى من الضريبة', 'Exempt', 'VAT exempt', 'exempt' => 'exempt',
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
                        'vat_justification' => $vatJustification,
                        'sku' => $sku,
                        'barcode' => $barcode,
                        'variant_name_ar' => $variantNameAr,
                        'variant_name_en' => $variantNameEn,
                        'image_url' => $imageUrl,
                        'image_bytes' => $isExcel ? ($this->embeddedImages[$line] ?? null) : null,
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
                }
                $sku = $row['sku'];
                $barcode = $row['barcode'];
                $rowErrors = [];
                if ($imageForm && $isExcel && $embeddedHint !== '' && $row['image_bytes'] === null)
                    $rowErrors[] = 'صورة Excel: العمود مخصص للصورة داخل الخلية؛ استخدم Insert > Pictures > Place over Cells وحدد موضعها في العمود N بنفس الصف';
                if ($row['image_url'] !== '' && $row['image_bytes'] !== null)
                    $rowErrors[] = 'الصورة: استخدم رابط الصورة أو الصورة المضمنة، وليس كليهما';
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
                $justification = trim((string) $row['vat_justification']);
                if (in_array($row['vat_class'], ['zero', 'exempt'], true) &&
                    (mb_strlen($justification) < 15 || mb_strlen($justification) > 500))
                    $rowErrors[] = 'السبب النظامي: للصفرية أو المعفاة اكتب سببًا نظاميًا واضحًا من 15 إلى 500 حرف في العمود O';
                if ($row['vat_class'] === 'standard' && $justification !== '')
                    $rowErrors[] = 'السبب النظامي: اتركه فارغًا للضريبة القياسية';
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

        // Download and validate images before opening the database transaction.
        // Clean up every stored file if any image or DB operation fails.
        $storedImages = [];
        try {
            foreach ($rows as $index => $row) {
                if ($row['image_bytes'] !== null) {
                    $storedImages[$index] = SellerProductImageSource::storeBytes($row['image_bytes'], $sellerId);
                } elseif ($row['image_url'] !== '') {
                    $storedImages[$index] = SellerProductImageSource::storeUrl($row['image_url'], $sellerId);
                }
            }

        $created = DB::transaction(function () use ($rows, $sellerId, $storedImages): int {
            $skus = array_column($rows, 'sku');
            $barcodes = array_values(array_filter(array_column($rows, 'barcode'), static fn ($v) => $v !== ''));
            if (DB::table('product_variants')->whereIn('sku', $skus)
                ->orWhereIn('barcode', $barcodes)->exists()) {
                throw ValidationException::withMessages(['file' => ['One or more SKUs/barcodes already exist.']]);
            }

            foreach ($rows as $index => $row) {
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
        } catch (\Throwable $exception) {
            if ($storedImages !== []) {
                Storage::disk('public')->delete(array_values($storedImages));
            }
            throw $exception;
        }

        return response()->json(['data' => ['created_drafts' => $created]], 201);
    }
}
