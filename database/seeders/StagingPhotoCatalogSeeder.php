<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\SellerStatus;
use App\Domain\Catalog\Models\Branch;
use App\Domain\Catalog\Models\BranchStock;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Catalog\Models\ServiceZone;
use App\Domain\Pricing\VatClass;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Staging-only showroom: two actual-photograph demo products per category.
 * Sources are licensed Wikimedia Commons photographs, never the category illustrations.
 * Previous published products are archived (draft), not deleted: order FKs stay intact.
 *
 * Requires working HTTPS to commons.wikimedia.org and upload.wikimedia.org,
 * a writable public/images directory, and manual inspection of downloaded photographs.
 * Never called by DatabaseSeeder. Never allowed on production.
 */
final class StagingPhotoCatalogSeeder extends Seeder
{
    private const IMAGE_DIR = 'images/staging-photo-products';

    /** English keyword is used to locate a real photograph (not stock art). */
    private const GROUPS = [
        ['personal-care', 'العناية الشخصية', 'Personal care', 'personal-care.webp', [
            ['shampoo-bottle', 'عبوة شامبو', 'Shampoo bottle', 'shampoo', 350],
            ['toothbrush', 'فرشاة أسنان', 'Toothbrush', 'toothbrush', 275],
        ]],
        ['makeup', 'مستحضرات التجميل', 'Makeup', 'makeup.webp', [
            ['lipstick', 'أحمر شفاه', 'Lipstick', 'lipstick', 450],
            ['mascara', 'ماسكارا', 'Mascara', 'mascara', 450],
        ]],
        ['perfumes', 'العطور', 'Perfumes', 'perfumes.webp', [
            ['perfume-bottle', 'عبوة عطر', 'Perfume bottle', 'perfume bottle', 500],
            ['cologne-bottle', 'عبوة كولونيا', 'Cologne bottle', 'cologne bottle', 500],
        ]],
        ['stationery', 'الأدوات المكتبية', 'Stationery', 'stationery.webp', [
            ['ballpoint-pen', 'قلم حبر جاف', 'Ballpoint pen', 'ballpoint pen', 200],
            ['notebook', 'دفتر ملاحظات', 'Notebook', 'notebook', 350],
        ]],
        ['school-supplies', 'المستلزمات المدرسية', 'School supplies', 'school-supplies.webp', [
            ['backpack', 'حقيبة مدرسية', 'School backpack', 'backpack', 500],
            ['colored-pencils', 'أقلام تلوين', 'Colored pencils', 'colored pencils', 325],
        ]],
        ['cleaning-products', 'المنظفات', 'Cleaning products', 'cleaning-products.webp', [
            ['laundry-detergent', 'مسحوق غسيل', 'Laundry detergent', 'laundry detergent', 400],
            ['dishwashing-liquid', 'سائل غسيل الصحون', 'Dishwashing liquid', 'dishwashing liquid', 325],
        ]],
        ['cleaning-tools', 'أدوات التنظيف', 'Cleaning tools', 'cleaning-tools.webp', [
            ['broom', 'مكنسة يدوية', 'Broom', 'broom', 400],
            ['mop', 'ممسحة أرضيات', 'Floor mop', 'mop', 450],
        ]],
        ['kitchenware', 'أدوات المطبخ', 'Kitchenware', 'kitchenware.webp', [
            ['frying-pan', 'مقلاة', 'Frying pan', 'frying pan', 500],
            ['cutlery', 'ملاعق وشوك', 'Cutlery', 'cutlery', 425],
        ]],
        ['groceries-fresh', 'الخضار والفواكه', 'Groceries & fresh produce', 'groceries-fresh.webp', [
            ['red-apples', 'تفاح أحمر', 'Red apples', 'red apples', 400],
            ['tomatoes', 'طماطم', 'Tomatoes', 'tomatoes', 350],
        ]],
    ];

    public function run(): void
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();

        if (! app()->environment('staging') || config('database.default') !== 'mysql' || $database !== 'market575_staging_clone') {
            throw new RuntimeException('This photo catalogue can ONLY run in APP_ENV=staging on market575_staging_clone MySQL.');
        }

        $directory = public_path(self::IMAGE_DIR);
        File::ensureDirectoryExists($directory);
        if (! is_writable($directory)) {
            throw new RuntimeException("Photo directory is not writable: {$directory}");
        }

        $attributions = [];
        $products = [];
        $failures = [];

        // Fetch and verify ALL 18 image files BEFORE modifying the database.
        foreach (self::GROUPS as [$categorySlug, $categoryAr, $categoryEn, $categoryImage, $entries]) {
            foreach ($entries as [$slug, $nameAr, $nameEn, $term, $price]) {
                $fileName = "{$slug}.jpg";
                $localPath = "{$directory}/{$fileName}";
                try {
                    $image = $this->photoFromCommons($term, $localPath);
                } catch (RuntimeException $exception) {
                    // A 429 is a provider-wide rate limit. Never send the remaining requests.
                    if (str_contains($exception->getMessage(), '429')) {
                        $this->command?->warn('Wikimedia rate limit reached; stop this preview now. No DB changes made.');
                        throw $exception;
                    }
                    if (getenv('ALL575_PHOTO_PREVIEW_ONLY') !== '1') {
                        throw $exception;
                    }

                    $failures[] = ['product' => $slug, 'keyword' => $term, 'error' => $exception->getMessage()];
                    $this->command?->warn("Photo unavailable for {$nameEn}: {$exception->getMessage()}");

                    continue;
                }
                $attributions[] = [
                    'product' => $slug,
                    'title' => $image['title'],
                    'source' => $image['page'],
                    'license' => $image['license'],
                    'artist' => $image['artist'],
                ];
                $products[] = [$categorySlug, $categoryAr, $categoryEn, $categoryImage, $slug, $nameAr, $nameEn, $price, $fileName];
                $this->command?->line("Photo ready: {$nameEn} / {$image['title']}");
            }
        }

        $manifestPath = "{$directory}/ATTRIBUTION.json";
        File::put($manifestPath, json_encode($attributions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        File::put("{$directory}/MISSING.json", json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        // A safe image-only preview: downloads pictures and attribution but NEVER writes to the database.
        // Run with ALL575_PHOTO_PREVIEW_ONLY=1 before reviewing each picture and licence.
        if (getenv('ALL575_PHOTO_PREVIEW_ONLY') === '1') {
            $this->command?->info('Preview complete: '.count($products).' of 18 photos saved; '.count($failures).' missing. NO database changes made.');
            $this->command?->info("Review each image and its licence: {$manifestPath}");

            return;
        }

        if (count($products) !== 18 || count($failures) !== 0) {
            throw new RuntimeException('Refusing publication: all 18 validated photos are required.');
        }

        // Publication is a separate, opt-in operation after human photo review.
        if (getenv('ALL575_PHOTOS_REVIEWED') !== 'YES') {
            throw new RuntimeException('Refusing to publish: review all downloaded product photos and licences first. Set ALL575_PHOTOS_REVIEWED=YES only after review.');
        }

        // Keep a reversible publication-status snapshot, not a destructive truncation.
        $snapshot = Product::query()->where('status', ProductStatus::Published->value)
            ->get(['id', 'slug', 'status'])->map(fn (Product $product) => [
                'id' => $product->id,
                'slug' => $product->slug,
                'status' => $product->status->value,
            ])->all();
        $backupPath = storage_path('app/staging-photo-catalog-prior-publications-'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($backupPath));
        File::put($backupPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($products): void {
            $zone = ServiceZone::query()->firstOrCreate(
                ['name_en' => 'Riyadh'],
                ['name_ar' => 'الرياض', 'center_lat' => 24.7136, 'center_lng' => 46.6753,
                    'radius_meters' => 40000, 'delivery_fee_halalas' => 1000, 'free_delivery_threshold_halalas' => 5000],
            );
            $seller = Seller::query()->firstOrCreate(
                ['slug' => 'staging-photo-showroom'],
                ['name_ar' => 'متجر الصور التجريبي', 'name_en' => 'Staging Photo Showroom', 'status' => SellerStatus::Approved],
            );
            $seller->update(['status' => SellerStatus::Approved]);
            $branch = Branch::query()->firstOrCreate(
                ['code' => 'STAGING-PHOTO-SHOWROOM'],
                ['seller_id' => $seller->id, 'service_zone_id' => $zone->id,
                    'name_ar' => 'الفرع التجريبي', 'name_en' => 'Demo branch',
                    'lat' => 24.7136, 'lng' => 46.6753, 'is_active' => true],
            );

            $slugs = array_map(static fn (array $entry): string => 'photo-'.$entry[4], $products);
            Product::query()->where('status', ProductStatus::Published->value)
                ->whereNotIn('slug', $slugs)->update(['status' => ProductStatus::Draft->value]);

            $categoryIds = [];
            foreach ($products as [$categorySlug, $categoryAr, $categoryEn, $categoryImage, $slug, $nameAr, $nameEn, $price, $fileName]) {
                if (! isset($categoryIds[$categorySlug])) {
                    $category = Category::query()->firstOrCreate(
                        ['slug' => $categorySlug],
                        ['name_ar' => $categoryAr, 'name_en' => $categoryEn,
                            'sort_order' => 100 + count($categoryIds),
                            'is_active' => true],
                    );
                    $category->update(['name_ar' => $categoryAr, 'name_en' => $categoryEn, 'is_active' => true]);
                    $categoryIds[$categorySlug] = $category->id;
                }

                $product = Product::query()->updateOrCreate(
                    ['slug' => 'photo-'.$slug],
                    ['seller_id' => $seller->id,
                        'category_id' => $categoryIds[$categorySlug],
                        'name_ar' => $nameAr, 'name_en' => $nameEn,
                        'description_ar' => 'منتج تجريبي للعرض فقط؛ الصورة توضيحية ولا تؤكد توفر العلامة التجارية أو المنتج.',
                        'description_en' => 'Demo listing only; photograph is illustrative and does not prove item or brand availability.',
                        'image_path' => self::IMAGE_DIR.'/'.$fileName,
                        'price_halalas' => $price, 'vat_class' => VatClass::Standard,
                        'status' => ProductStatus::Published, 'is_featured' => true],
                );
                $variant = ProductVariant::query()->updateOrCreate(
                    ['sku' => 'STPHOTO-'.strtoupper($slug)],
                    ['product_id' => $product->id, 'is_active' => true, 'sort_order' => 0],
                );
                BranchStock::query()->updateOrCreate(
                    ['branch_id' => $branch->id, 'variant_id' => $variant->id],
                    ['on_hand' => 30, 'reserved' => 0],
                );
            }
        });

        $this->command?->info('18 photo-demo products published (two per category). Previous published products archived, not deleted.');
        $this->command?->info("Photo licensing: {$manifestPath}");
        $this->command?->info("Previous publication snapshot: {$backupPath}");
        $this->command?->warn('Review all photos and Commons license/attribution before public use. Staging only.');
    }

    /** @return array{title: string, page: string, license: string, artist: string} */
    private function photoFromCommons(string $term, string $path): array
    {
        // Match real Commons JPEG photos to exact product keywords, not AI generated category art.
        $response = Http::withHeaders([
            'User-Agent' => 'ALL575StagingPhotoCatalog/1.0 (github.com/EhsasElias/all-575-api)',
        ])->timeout(20)->get('https://commons.wikimedia.org/w/api.php', [
            'action' => 'query', 'format' => 'json', 'generator' => 'search',
            'gsrsearch' => 'filetype:bitmap '.$term, 'gsrnamespace' => 6, 'gsrlimit' => 40,
            'prop' => 'imageinfo', 'iiprop' => 'url|size|mime|extmetadata', 'iiurlwidth' => 640,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException("Wikimedia Commons search unavailable for {$term} ({$response->status()}). Stop requesting until rate limit clears.");
        }

        $pages = array_values($response->json('query.pages', []));
        $rejectionCounts = ['name' => 0, 'mime' => 0, 'license' => 0, 'url' => 0, 'download' => 0, 'size' => 0];
        $sampleLicenses = [];
        $sampleUrls = [];
        foreach ($pages as $page) {
            $title = (string) ($page['title'] ?? '');
            $name = strtolower($title);
            $needle = strtolower(explode(' ', $term)[0]);
            if (! str_contains($name, $needle) || preg_match('/logo|icon|drawing|illustration|diagram|clipart|cartoon|poster/', $name)) {
                $rejectionCounts['name']++;
                continue;
            }

            $info = $page['imageinfo'][0] ?? null;
            if (! is_array($info) || ($info['mime'] ?? '') !== 'image/jpeg') {
                $rejectionCounts['mime']++;
                continue;
            }
            $metadata = $info['extmetadata'] ?? [];
            $license = trim(strip_tags((string) ($metadata['LicenseShortName']['value'] ?? '')));
            $sampleLicenses[$license === '' ? '(missing)' : $license] = true;
            if (! preg_match('/^(?:CC0(?: [0-9.]+)?|CC BY(?:-SA)?(?: [0-9.]+)?|Public domain|PD(?:-[A-Za-z0-9]+)?)$/i', $license)) {
                $rejectionCounts['license']++;
                continue;
            }
            // Commons occasionally omits thumburl or returns alternate CDN URL forms.
            // Never use a different host; fall back to the original canonical upload URL.
            $allowedUrl = static fn (string $candidate): bool =>
                parse_url($candidate, PHP_URL_SCHEME) === 'https'
                && parse_url($candidate, PHP_URL_HOST) === 'upload.wikimedia.org';
            $thumb = (string) ($info['thumburl'] ?? '');
            $original = (string) ($info['url'] ?? '');
            $url = $allowedUrl($thumb) ? $thumb : ($allowedUrl($original) ? $original : '');
            if ($url === '') {
                $rejectionCounts['url']++;
                if (count($sampleUrls) < 3) {
                    $sampleUrls[] = [
                        'file' => $title,
                        'thumb_host' => parse_url($thumb, PHP_URL_HOST),
                        'url_host' => parse_url($original, PHP_URL_HOST),
                        'thumb_present' => $thumb !== '',
                        'url_present' => $original !== '',
                    ];
                }
                continue;
            }
            $download = Http::withHeaders([
                'User-Agent' => 'ALL575StagingPhotoCatalog/1.0 (github.com/EhsasElias/all-575-api)',
            ])->timeout(25)->get($url);
            if (! $download->successful()) {
                if ($download->status() === 429) {
                    throw new RuntimeException("Wikimedia upload CDN returned HTTP 429. Stop and retry later; database unchanged.");
                }
                $rejectionCounts['download']++;
                continue;
            }
            $bytes = $download->body();
            if (strlen($bytes) < 10000) {
                $rejectionCounts['size']++;
                continue;
            }
            $imageSize = getimagesizefromstring($bytes);
            if (! is_array($imageSize) || $imageSize[2] !== IMAGETYPE_JPEG || $imageSize[0] < 240 || $imageSize[1] < 240) {
                $rejectionCounts['size']++;
                continue;
            }
            if (File::put($path, $bytes) === false) {
                throw new RuntimeException("Unable to save image to {$path}");
            }

            return [
                'title' => $title,
                'page' => (string) ($info['descriptionurl'] ?? ''),
                'license' => $license,
                'artist' => trim(strip_tags((string) ($metadata['Artist']['value'] ?? ''))),
            ];
        }

        $reasons = json_encode($rejectionCounts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $licenses = implode(', ', array_keys($sampleLicenses));
        $urls = json_encode($sampleUrls, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        throw new RuntimeException("No suitable JPEG for {$term}; results=".count($pages)."; rejected={$reasons}; observed_licenses={$licenses}; sampled_url_shapes={$urls}. Database unchanged.");
    }
}
