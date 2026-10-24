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
use App\Domain\Catalog\Services\ProductPricing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Demo data for local development and screenshots. Delivery fees, stock and the prices that differ
 * from 5.75 are placeholders, not business decisions. Never runs in production.
 */
class DemoCatalogSeeder extends Seeder
{
    /** slug => [name_ar, name_en] */
    private const CATEGORIES = [
        'food' => ['أطعمة', 'Food'],
        'meat' => ['اللحوم', 'Meat'],
        'groceries' => ['البقالة', 'Groceries'],
        'gifts' => ['هدايا', 'Gifts'],
        'pet-supplies' => ['مستلزمات الحيوانات الأليفة', 'Pet Supplies'],
        'accessories' => ['الإكسسوارات', 'Accessories'],
        'clothing' => ['الملابس', 'Clothing'],
        'stationery' => ['القرطاسية', 'Stationery'],
        'beauty' => ['التجميل', 'Beauty'],
        'home' => ['المنزل', 'Home'],
        'kitchen' => ['المطبخ', 'Kitchen'],
        'toys' => ['الألعاب', 'Toys'],
        'electronics' => ['إكسسوارات إلكترونية', 'Electronics Accessories'],
        'personal-care' => ['العناية الشخصية', 'Personal Care'],
        'cleaning' => ['التنظيف', 'Cleaning'],
    ];

    /** slug => [name_ar, name_en] */
    private const SELLERS = [
        'home-store' => ['متجر البيت', 'Home Store'],
        'beauty-world' => ['عالم الجمال', 'Beauty World'],
        'stationery-corner' => ['ركن القرطاسية', 'Stationery Corner'],
        'value-store' => ['متجر التوفير', 'Value Store'],
    ];

    /** category => [seller, [[name_ar, name_en], x3]] */
    private const PRODUCTS = [
        'food' => ['home-store', [['بسكويت', 'Biscuits'], ['شوكولاتة', 'Chocolate'], ['شاي', 'Tea']]],
        'clothing' => ['beauty-world', [['جوارب', 'Socks'], ['قبعة', 'Cap'], ['وشاح', 'Scarf']]],
        'stationery' => ['stationery-corner', [['دفتر', 'Notebook'], ['أقلام', 'Pens'], ['ملصقات', 'Stickers']]],
        'beauty' => ['value-store', [['أحمر شفاه', 'Lipstick'], ['فرشاة مكياج', 'Makeup Brush'], ['مرآة صغيرة', 'Pocket Mirror']]],
        'home' => ['home-store', [['منظم أدراج', 'Drawer Organizer'], ['علاقة ملابس', 'Clothes Hanger'], ['قطعة تنظيف', 'Cleaning Cloth']]],
        'kitchen' => ['beauty-world', [['كوب', 'Mug'], ['ملعقة سيليكون', 'Silicone Spoon'], ['علبة حفظ', 'Storage Container']]],
        'toys' => ['stationery-corner', [['كرة صغيرة', 'Mini Ball'], ['مكعبات', 'Building Blocks'], ['لعبة ألغاز', 'Puzzle']]],
        'accessories' => ['value-store', [['مشبك شعر', 'Hair Clip'], ['سوار', 'Bracelet'], ['ميدالية مفاتيح', 'Keychain']]],
        'electronics' => ['home-store', [['حامل جوال', 'Phone Stand'], ['منظم كابلات', 'Cable Organizer'], ['غطاء سماعة', 'Earbud Case']]],
        'personal-care' => ['beauty-world', [['مشط', 'Comb'], ['صابون', 'Soap'], ['إسفنجة استحمام', 'Bath Sponge']]],
        'gifts' => ['stationery-corner', [['كيس هدية', 'Gift Bag'], ['بطاقة تهنئة', 'Greeting Card'], ['ورق تغليف', 'Wrapping Paper']]],
        'cleaning' => ['value-store', [['فرشاة تنظيف', 'Cleaning Brush'], ['إسفنجة تنظيف', 'Cleaning Sponge'], ['قفازات', 'Gloves']]],
    ];

    /** product slug => price as typed by the seller (halalas) */
    private const PRICE_OVERRIDES = ['demo-stationery-2' => 350, 'demo-electronics-1' => 750];

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
                'image_path' => "images/all575/categories/{$slug}.png", 'is_active' => true,
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
        $pricing = app(ProductPricing::class);

        foreach (self::PRODUCTS as $categorySlug => [$sellerSlug, $items]) {
            foreach ($items as $index => [$ar, $en]) {
                $slug = 'demo-'.$categorySlug.'-'.($index + 1);
                $branch = $branches[$sellerSlug];

                // The first mug is typed without VAT (5.00 + 15% = 5.75) to exercise both entry modes.
                $includesVat = $slug !== 'demo-kitchen-1';
                $typedPrice = self::PRICE_OVERRIDES[$slug] ?? ($includesVat ? 575 : 500);

                $product = Product::query()->updateOrCreate(['slug' => $slug], [
                    'seller_id' => $branch->seller_id, 'category_id' => $categories[$categorySlug]->id,
                    'name_ar' => $ar, 'name_en' => $en,
                    'description_ar' => 'منتج تجريبي لعرض المتجر.', 'description_en' => 'Demo product for previewing the store.',
                    'image_path' => "images/all575/products/{$slug}.png",
                    'price_includes_vat' => $includesVat, 'status' => ProductStatus::Published, 'is_featured' => $index === 0,
                ]);

                $variants = $slug === 'demo-clothing-1' ? ['S', 'M', 'L'] : [null];

                foreach ($variants as $position => $size) {
                    $variant = ProductVariant::query()->updateOrCreate(['sku' => strtoupper($slug).($size ? "-{$size}" : '')], [
                        'product_id' => $product->id, 'price_halalas' => $typedPrice, 'sort_order' => $position,
                        'name_ar' => $size, 'name_en' => $size,
                    ]);

                    BranchStock::query()->updateOrCreate(
                        ['branch_id' => $branch->id, 'variant_id' => $variant->id],
                        ['on_hand' => 100, 'reserved' => 0],
                    );
                }

                $pricing->refreshPriceRange($product->refresh());
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
