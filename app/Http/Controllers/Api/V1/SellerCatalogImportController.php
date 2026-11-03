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
            if ($header !== self::HEADERS) {
                throw ValidationException::withMessages(['file' => ['Invalid column order. Expected: '.implode(', ', self::HEADERS)]]);
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
                if (count($cells) !== count(self::HEADERS)) {
                    $errors[] = "Line {$line}: expected 7 columns.";
                    continue;
                }
                $row = array_combine(self::HEADERS, array_map('trim', $cells));
                $sku = $row['sku'];
                $barcode = $row['barcode'];
                if (!ctype_digit($row['category_id']) || !ctype_digit($row['price_halalas'])
                    || (int) $row['price_halalas'] < 100 || (int) $row['price_halalas'] > 575
                    || !in_array($row['vat_class'], array_column(VatClass::cases(), 'value'), true)
                    || $sku === '' || strlen($sku) > 100
                    || $barcode !== '' && strlen($barcode) > 100
                    || $row['name_ar'] === '' || $row['name_en'] === ''
                    || mb_strlen($row['name_ar']) > 180 || mb_strlen($row['name_en']) > 180) {
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
