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
        'رمز SKU (مطلوب)', 'الباركود (اختياري)', 'المتجر', 'التصنيف',
        'اسم المنتج بالعربية', 'اسم المنتج بالإنجليزية', 'الوصف بالعربية',
        'الوصف بالإنجليزية', 'السعر بالريال (1–10)', 'فئة الضريبة',
    ];
    private const LEGACY_FORM_HEADERS = [
        'رمز SKU (مطلوب)', 'الباركود (اختياري)', 'المتجر', 'التصنيف',
        'اسم المنتج بالعربية', 'اسم المنتج بالإنجليزية', 'الوصف بالعربية',
        'الوصف بالإنجليزية', 'السعر بالريال (1–10)', 'فئة الضريبة',
    ];
    private const LEGACY_ENGLISH_FORM_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store', 'Category',
        'Arabic product name', 'English product name', 'Arabic description',
        'English description', 'Price SAR (1–5.75)', 'VAT class',
    ];
    private const ENGLISH_FORM_HEADERS = [
        'SKU code (required)', 'Barcode (optional)', 'Store', 'Category',
        'Arabic product name', 'English product name', 'Arabic description',
        'English description', 'Price SAR (1–10)', 'VAT class',
    ];


    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'seller_id' => ['required', 'integer', 'min:1'],
            'dry_run' => ['sometimes', 'boolean'],
            'file' => ['required', 'file', 'max:1024', 'mimes:csv,txt'],
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

        $handle = fopen($input['file']->getRealPath(), 'rb');
        if ($handle === false) {
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
            $formTemplate = in_array($header, [self::FORM_HEADERS, self::ENGLISH_FORM_HEADERS, self::LEGACY_FORM_HEADERS, self::LEGACY_ENGLISH_FORM_HEADERS], true);
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
                $expectedColumns = $formTemplate ? count(self::FORM_HEADERS) : count(self::HEADERS);
                if (count($cells) !== $expectedColumns) {
                    $errors[] = "Line {$line}: expected {$expectedColumns} columns.";
                    continue;
                }
                $values = array_map('trim', $cells);
                if ($formTemplate) {
                    [$sku, $barcode, $storeValue, $category, $nameAr, $nameEn,
                        $descriptionAr, $descriptionEn, $priceSar, $vatLabel] = $values;
                    if ($storeValue !== '' && !in_array($storeValue, $validStoreNames, true)) {
                        $errors[] = "Line {$line}: store does not match the selected seller.";
                        continue;
                    }
                    // Avoid float rounding for prices; the UI shows SAR and the API stores halalas.
                    if (!preg_match('/^[0-9]+(?:\\.[0-9]{1,2})?$/D', $priceSar)) {
                        $errors[] = "Line {$line}: invalid SAR price.";
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
                            $errors[] = "Line {$line}: unknown or ambiguous category name.";
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
                    ];
                } else {
                    $row = array_combine(self::HEADERS, $values);
                    $row['description_ar'] = '';
                    $row['description_en'] = '';
                }
                $sku = $row['sku'];
                $barcode = $row['barcode'];
                if (!ctype_digit($row['category_id']) || !ctype_digit($row['price_halalas'])
                    || (int) $row['price_halalas'] < 100 || (int) $row['price_halalas'] > 1000
                    || !in_array($row['vat_class'], array_column(VatClass::cases(), 'value'), true)
                    || $sku === '' || strlen($sku) > 100
                    || $barcode !== '' && strlen($barcode) > 100
                    || $row['name_ar'] === '' || $row['name_en'] === ''
                    || mb_strlen($row['name_ar']) > 180 || mb_strlen($row['name_en']) > 180
                    || mb_strlen($row['description_ar']) > 10000 || mb_strlen($row['description_en']) > 10000) {
                    $errors[] = "Line {$line}: invalid product fields.";
                    continue;
                }
                if (isset($seenSkus[$sku]) || ($barcode !== '' && isset($seenBarcodes[$barcode]))) {
                    $errors[] = "Line {$line}: duplicate SKU or barcode in upload.";
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
        }

        if ($rows === []) {
            $errors[] = 'No valid product rows.';
        }

        $categoryIds = array_unique(array_map(static fn (array $row): int => (int) $row['category_id'], $rows));
        $validCategories = DB::table('categories')->where('is_active', true)->whereIn('id', $categoryIds)->pluck('id')->all();
        if (count($validCategories) !== count($categoryIds)) {
            $errors[] = 'Some categories do not exist or are inactive.';
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
                    'is_active' => true,
                    'sort_order' => 0,
                ]);
            }
            return count($rows);
        }, 3);

        return response()->json(['data' => ['created_drafts' => $created]], 201);
    }
}
