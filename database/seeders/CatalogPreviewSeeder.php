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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * An explicitly invoked, non-destructive storefront preview for an empty VPS catalog.
 *
 * Unlike DemoCatalogSeeder, this can run with APP_ENV=production. It only creates missing
 * preview records and never changes existing products, inventory, or fulfillment data.
 * All preview items are labelled and have zero stock in an inactive branch and zone.
 * They cannot be purchased: replace them with real, verified seller listings before launch.
 */
final class CatalogPreviewSeeder extends Seeder
{
    /** Category slug => [Arabic name, English name, Arabic product, English product, price in halalas]. */
    private const ITEMS = [
        'clothing' => ['ملابس', 'Clothing', 'تيشيرت قطني', 'Cotton T-shirt', 575],
        'toys' => ['ألعاب', 'Toys', 'دب محشو', 'Teddy bear', 500],
        'cookware' => ['أواني مطبخ', 'Cookware', 'قدر مطبخ', 'Cooking pot', 575],
        'cleaning' => ['منظفات', 'Cleaning products', 'مسحوق غسيل', 'Washing powder', 400],
        'stationery' => ['أدوات مكتبية', 'Stationery', 'أقلام', 'Pens', 200],
        'accessories' => ['أدوات مكملة', 'Accessories', 'نظارة شمسية', 'Sunglasses', 350],
        'hand-tools' => ['أدوات معالجة', 'Hand tools', 'مفاتيح ربط', 'Wrenches', 575],
        'shampoos' => ['شامبوهات', 'Shampoos', 'شامبو', 'Shampoo', 300],
        'other' => ['أخرى', 'Other', 'سلة تخزين', 'Storage basket', 250],
        'vegetables' => ['خضار', 'Vegetables', 'طماطم', 'Tomatoes', 150],
        'fruits' => ['فواكه', 'Fruits', 'تفاح أحمر', 'Red apples', 200],
        'kitchen-utensils' => ['أدوات المطبخ', 'Kitchen utensils', 'طقم ملاعق وشوك', 'Cutlery set', 400],
        'shoes' => ['أحذية', 'Shoes', 'حذاء رياضي', 'Sneakers', 575],
        'towels' => ['مناشف', 'Towels', 'مناشف قطنية', 'Cotton towels', 300],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            // Never enable delivery or inventory for an unverified preview seller.
            $zone = ServiceZone::query()->firstOrCreate(
                ['name_en' => 'Market575 Preview (No Delivery)'],
                [
                    'name_ar' => 'عرض Market575 التجريبي (لا يوجد توصيل)',
                    'center_lat' => 24.7136,
                    'center_lng' => 46.6753,
                    'radius_meters' => 1,
                    'delivery_fee_halalas' => 0,
                    'is_active' => false,
                ],
            );

            $seller = Seller::query()->firstOrCreate(
                ['slug' => 'market575-preview'],
                [
                    'name_ar' => 'معرض تجريبي - غير متاح للشراء',
                    'name_en' => 'Preview Catalog - Not for Sale',
                    'status' => SellerStatus::Approved,
                ],
            );

            $branch = Branch::query()->firstOrCreate(
                ['code' => 'MARKET575-PREVIEW-NO-SALES'],
                [
                    'seller_id' => $seller->id,
                    'service_zone_id' => $zone->id,
                    'name_ar' => 'معرض للصور فقط',
                    'name_en' => 'Display only',
                    'is_active' => false,
                ],
            );

            $position = 0;

            foreach (self::ITEMS as $slug => [$categoryAr, $categoryEn, $productAr, $productEn, $price]) {
                $category = Category::query()->firstOrCreate(
                    ['slug' => $slug],
                    [
                        'name_ar' => $categoryAr,
                        'name_en' => $categoryEn,
                        'image_path' => "images/all575/category-art/{$slug}.png",
                        'sort_order' => ++$position,
                        'is_active' => true,
                    ],
                );

                $previewSlug = "preview-{$slug}";
                $product = Product::query()->firstOrCreate(
                    ['slug' => $previewSlug],
                    [
                        'seller_id' => $seller->id,
                        'category_id' => $category->id,
                        'name_ar' => $productAr.' (عرض تجريبي)',
                        'name_en' => $productEn.' (Preview)',
                        'description_ar' => 'صورة وعرض تجريبي فقط. المنتج غير متاح للشراء ولا يمثل مخزوناً حقيقياً.',
                        'description_en' => 'Storefront preview only. Not available to purchase; not verified seller inventory.',
                        'image_path' => "images/all575/category-art/{$slug}.png",
                        'price_halalas' => $price,
                        'status' => ProductStatus::Published,
                        'is_featured' => true,
                    ],
                );

                $variant = ProductVariant::query()->firstOrCreate(
                    ['sku' => 'M575-PREVIEW-'.strtoupper($slug)],
                    ['product_id' => $product->id],
                );

                BranchStock::query()->firstOrCreate(
                    ['branch_id' => $branch->id, 'variant_id' => $variant->id],
                    ['on_hand' => 0, 'reserved' => 0],
                );
            }
        });
    }
}
