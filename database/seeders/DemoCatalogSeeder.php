<?php

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
use App\Domain\Catalog\Models\WelcomeSlide;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Demo data for local development and screenshots. Delivery fees and stock are placeholders, not business
 * decisions (every product costs the platform price in config/store.php). Never runs in production.
 */
class DemoCatalogSeeder extends Seeder
{
    /** slug => [name_ar, name_en], in the order the store lists them. Each has a picture in public/images/all575/category-art. */
    private const CATEGORIES = [
        'clothing' => ['ملابس', 'Clothing'],
        'toys' => ['ألعاب', 'Toys'],
        'cookware' => ['أواني مطبخ', 'Cookware'],
        'cleaning' => ['منظفات', 'Cleaning products'],
        'stationery' => ['أدوات مكتبية', 'Stationery'],
        'accessories' => ['أدوات مكملة', 'Accessories'],
        'hand-tools' => ['أدوات معالجة', 'Hand tools'],
        'shampoos' => ['شامبوهات', 'Shampoos'],
        'other' => ['أخرى', 'Other'],
        'vegetables' => ['خضار', 'Vegetables'],
        'fruits' => ['فواكه', 'Fruits'],
        'kitchen-utensils' => ['أدوات المطبخ', 'Kitchen utensils'],
        'shoes' => ['أحذية', 'Shoes'],
        'towels' => ['مناشف', 'Towels'],
    ];

    /** slug => [name_ar, name_en] */
    private const SELLERS = [
        'home-store' => ['متجر البيت', 'Home Store'],
        'beauty-world' => ['عالم الجمال', 'Beauty World'],
        'stationery-corner' => ['ركن القرطاسية', 'Stationery Corner'],
        'value-store' => ['متجر التوفير', 'Value Store'],
    ];

    /** category => [seller, [[name_ar, name_en], ...]]. The product shows its category's picture. */
    private const PRODUCTS = [
        'clothing' => ['beauty-world', [['تيشيرت قطني', 'Cotton T-shirt']]],
        'toys' => ['stationery-corner', [['دب محشو', 'Teddy bear']]],
        'cookware' => ['home-store', [['قدر مطبخ', 'Cooking pot']]],
        'cleaning' => ['value-store', [['مسحوق غسيل', 'Washing powder']]],
        'stationery' => ['stationery-corner', [['أقلام', 'Pens']]],
        'accessories' => ['value-store', [['نظارة شمسية', 'Sunglasses']]],
        'hand-tools' => ['home-store', [['مفاتيح ربط', 'Wrenches']]],
        'shampoos' => ['beauty-world', [['شامبو', 'Shampoo']]],
        'other' => ['home-store', [['سلة تخزين', 'Storage basket']]],
        'vegetables' => ['home-store', [['طماطم', 'Tomatoes']]],
        'fruits' => ['home-store', [['تفاح أحمر', 'Red apples']]],
        'kitchen-utensils' => ['home-store', [['طقم ملاعق وشوك', 'Cutlery set']]],
        'shoes' => ['value-store', [['حذاء رياضي', 'Sneakers']]],
        'towels' => ['home-store', [['مناشف', 'Towels']]],
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new LogicException('DemoCatalogSeeder must not run in production.');
        }

        DB::transaction(function (): void {
            $zone = ServiceZone::query()->firstOrCreate(['name_en' => 'Riyadh'], [
                'name_ar' => 'الرياض', 'center_lat' => 24.7136, 'center_lng' => 46.6753,
                'radius_meters' => 40_000, 'delivery_fee_halalas' => 1000, 'free_delivery_threshold_halalas' => 5000,
            ]);

            $categories = $this->seedCategories();
            $branches = $this->seedSellersWithBranches($zone);
            $this->seedProducts($categories, $branches);
            $this->seedWelcomeSlides();
        });
    }

    /** @return array<string, Category> */
    private function seedCategories(): array
    {
        $order = 0;
        $categories = [];

        foreach (self::CATEGORIES as $slug => [$ar, $en]) {
            $categories[$slug] = Category::query()->updateOrCreate(['slug' => $slug], [
                'name_ar' => $ar, 'name_en' => $en, 'sort_order' => ++$order,
                'image_path' => "images/all575/category-art/{$slug}.png", 'is_active' => true,
            ]);
        }

        return $categories;
    }

    /** @return array<string, Branch> */
    private function seedSellersWithBranches(ServiceZone $zone): array
    {
        $branches = [];

        foreach (self::SELLERS as $slug => [$ar, $en]) {
            $seller = Seller::query()->updateOrCreate(['slug' => $slug], [
                'name_ar' => $ar, 'name_en' => $en, 'status' => SellerStatus::Approved,
            ]);

            $branches[$slug] = Branch::query()->updateOrCreate(['code' => 'DEMO-'.strtoupper($slug)], [
                'seller_id' => $seller->id, 'service_zone_id' => $zone->id,
                'name_ar' => 'الفرع الرئيسي', 'name_en' => 'Main branch', 'lat' => 24.7136, 'lng' => 46.6753,
            ]);
        }

        return $branches;
    }

    /**
     * @param  array<string, Category>  $categories
     * @param  array<string, Branch>  $branches
     */
    private function seedProducts(array $categories, array $branches): void
    {
        foreach (self::PRODUCTS as $categorySlug => [$sellerSlug, $items]) {
            foreach ($items as $index => [$ar, $en]) {
                $slug = 'demo-'.$categorySlug.'-'.($index + 1);
                $branch = $branches[$sellerSlug];

                $product = Product::query()->updateOrCreate(['slug' => $slug], [
                    'seller_id' => $branch->seller_id, 'category_id' => $categories[$categorySlug]->id,
                    'name_ar' => $ar, 'name_en' => $en,
                    'description_ar' => 'منتج تجريبي لعرض المتجر.', 'description_en' => 'Demo product for previewing the store.',
                    'image_path' => "images/all575/category-art/{$categorySlug}.png",
                    'status' => ProductStatus::Published, 'is_featured' => $index === 0,
                ]);

                $variants = $slug === 'demo-clothing-1' ? ['S', 'M', 'L'] : [null];

                foreach ($variants as $position => $size) {
                    $variant = ProductVariant::query()->updateOrCreate(['sku' => strtoupper($slug).($size ? "-{$size}" : '')], [
                        'product_id' => $product->id, 'sort_order' => $position,
                        'name_ar' => $size, 'name_en' => $size,
                    ]);

                    BranchStock::query()->updateOrCreate(
                        ['branch_id' => $branch->id, 'variant_id' => $variant->id],
                        ['on_hand' => 100, 'reserved' => 0],
                    );
                }
            }
        }
    }

    private function seedWelcomeSlides(): void
    {
        $slides = [
            ['food', 'خضار وفواكه', 'Fruit & vegetables'],
            ['cleaning', 'مستلزمات منزلية', 'Household essentials'],
            ['clothing', 'ملابس', 'Clothing'],
        ];

        foreach ($slides as $position => [$image, $ar, $en]) {
            WelcomeSlide::query()->updateOrCreate(['position' => $position + 1], [
                'image_path' => "images/all575/categories/{$image}.png", 'title_ar' => $ar, 'title_en' => $en, 'is_active' => true,
            ]);
        }
    }
}
