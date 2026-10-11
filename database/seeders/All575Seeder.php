<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Sample catalog only: does not recover the failed SQL export. */
class All575Seeder extends Seeder
{
    public function run(): void
    {
        foreach (['categories', 'sellers', 'products', 'sliders'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException("Missing {$table}. Run php artisan migrate --force first.");
            }
        }

        DB::transaction(function (): void {
            $categoryIds = [];
            foreach ($this->categories() as $row) {
                $categoryIds[$row['slug']] = $this->insertIfMissing('categories', ['slug' => $row['slug']], $row);
            }
            $sellerIds = [];
            foreach ($this->sellers() as $row) {
                $sellerIds[$row['slug']] = $this->insertIfMissing('sellers', ['slug' => $row['slug']], $row);
            }
            foreach ($this->products() as $row) {
                $row['category_id'] = $categoryIds[$row['category_slug']];
                $row['seller_id'] = $sellerIds[$row['seller_slug']];
                unset($row['category_slug'], $row['seller_slug']);
                $this->insertIfMissing('products', ['slug' => $row['slug']], $row);
            }
            foreach ($this->sliders() as $row) {
                // sliders has no slug column; use its bilingual titles as the seed identity.
                $this->insertIfMissing('sliders', [
                    'title_ar' => $row['title_ar'],
                    'title_en' => $row['title_en'],
                ], $row);
            }
            $this->createAdminIfConfigured();
        });

        $this->command?->info('All575 sample catalog seeded. Existing matching records were preserved.');
    }

    private function insertIfMissing(string $table, array $identity, array $row): int
    {
        $id = DB::table($table)->where($identity)->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        $now = now();
        return (int) DB::table($table)->insertGetId(array_merge($row, [
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    private function createAdminIfConfigured(): void
    {
        // getenv reads Railway service variables even with Laravel config cached.
        $email = trim((string) getenv('ALL575_ADMIN_EMAIL'));
        $password = (string) getenv('ALL575_ADMIN_PASSWORD');
        if ($email === '' && $password === '') {
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            throw new RuntimeException('Set ALL575_ADMIN_EMAIL and ALL575_ADMIN_PASSWORD (minimum 12 characters), or leave both unset.');
        }
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'role') || !Schema::hasColumn('users', 'is_active')) {
            throw new RuntimeException('Run the users and role/status migrations before creating an admin.');
        }
        $existing = DB::table('users')->where('email', $email)->first();
        if ($existing !== null) {
            if ($existing->role !== 'admin' || !$existing->is_active) {
                throw new RuntimeException('The requested admin email belongs to an existing non-admin or inactive account. Choose another email.');
            }
            return; // Never reset an existing password.
        }
        $this->insertIfMissing('users', ['email' => $email], [
            'name' => 'All 575 Administrator',
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => null,
        ]);
    }

    private function categories(): array
    {
        return [
            ['slug' => 'demo-food', 'name_ar' => 'الأغذية', 'name_en' => 'Food', 'image' => null, 'sort_order' => 1, 'is_active' => true],
            ['slug' => 'demo-clothing', 'name_ar' => 'الملابس', 'name_en' => 'Clothing', 'image' => null, 'sort_order' => 2, 'is_active' => true],
            ['slug' => 'demo-stationery', 'name_ar' => 'القرطاسية', 'name_en' => 'Stationery', 'image' => null, 'sort_order' => 3, 'is_active' => true],
            ['slug' => 'demo-beauty', 'name_ar' => 'التجميل', 'name_en' => 'Beauty', 'image' => null, 'sort_order' => 4, 'is_active' => true],
            ['slug' => 'demo-home', 'name_ar' => 'المنزل', 'name_en' => 'Home', 'image' => null, 'sort_order' => 5, 'is_active' => true],
            ['slug' => 'demo-kitchen', 'name_ar' => 'المطبخ', 'name_en' => 'Kitchen', 'image' => null, 'sort_order' => 6, 'is_active' => true],
            ['slug' => 'demo-toys', 'name_ar' => 'الألعاب', 'name_en' => 'Toys', 'image' => null, 'sort_order' => 7, 'is_active' => true],
            ['slug' => 'demo-accessories', 'name_ar' => 'الإكسسوارات', 'name_en' => 'Accessories', 'image' => null, 'sort_order' => 8, 'is_active' => true],
            ['slug' => 'demo-electronics', 'name_ar' => 'إكسسوارات إلكترونية', 'name_en' => 'Electronics Accessories', 'image' => null, 'sort_order' => 9, 'is_active' => true],
            ['slug' => 'demo-personal-care', 'name_ar' => 'العناية الشخصية', 'name_en' => 'Personal Care', 'image' => null, 'sort_order' => 10, 'is_active' => true],
            ['slug' => 'demo-gifts', 'name_ar' => 'الهدايا', 'name_en' => 'Gifts', 'image' => null, 'sort_order' => 11, 'is_active' => true],
            ['slug' => 'demo-cleaning', 'name_ar' => 'التنظيف', 'name_en' => 'Cleaning', 'image' => null, 'sort_order' => 12, 'is_active' => true],
        ];
    }

    private function sellers(): array
    {
        return [
            ['slug' => 'demo-seller-1', 'name_ar' => 'متجر البيت', 'name_en' => 'Home Store', 'description_ar' => 'متجر تجريبي لاختبار المنصة.', 'description_en' => 'Sample store for platform testing.', 'status' => 'approved', 'is_active' => true, 'commission_percentage' => '0.00', 'logo' => null],
            ['slug' => 'demo-seller-2', 'name_ar' => 'عالم الجمال', 'name_en' => 'Beauty World', 'description_ar' => 'متجر تجريبي لاختبار المنصة.', 'description_en' => 'Sample store for platform testing.', 'status' => 'approved', 'is_active' => true, 'commission_percentage' => '0.00', 'logo' => null],
            ['slug' => 'demo-seller-3', 'name_ar' => 'ركن القرطاسية', 'name_en' => 'Stationery Corner', 'description_ar' => 'متجر تجريبي لاختبار المنصة.', 'description_en' => 'Sample store for platform testing.', 'status' => 'approved', 'is_active' => true, 'commission_percentage' => '0.00', 'logo' => null],
            ['slug' => 'demo-seller-4', 'name_ar' => 'متجر التوفير', 'name_en' => 'Value Store', 'description_ar' => 'متجر تجريبي لاختبار المنصة.', 'description_en' => 'Sample store for platform testing.', 'status' => 'approved', 'is_active' => true, 'commission_percentage' => '0.00', 'logo' => null],
        ];
    }

    private function products(): array
    {
        return [
            ['slug' => 'demo-food-1', 'category_slug' => 'demo-food', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'بسكويت', 'name_en' => 'Biscuits', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-01-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-food-2', 'category_slug' => 'demo-food', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'شوكولاتة', 'name_en' => 'Chocolate', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-01-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-food-3', 'category_slug' => 'demo-food', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'شاي', 'name_en' => 'Tea', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-01-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-clothing-1', 'category_slug' => 'demo-clothing', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'جوارب', 'name_en' => 'Socks', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-02-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-clothing-2', 'category_slug' => 'demo-clothing', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'قبعة', 'name_en' => 'Cap', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-02-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-clothing-3', 'category_slug' => 'demo-clothing', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'وشاح', 'name_en' => 'Scarf', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-02-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-stationery-1', 'category_slug' => 'demo-stationery', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'دفتر', 'name_en' => 'Notebook', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-03-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-stationery-2', 'category_slug' => 'demo-stationery', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'أقلام', 'name_en' => 'Pens', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-03-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-stationery-3', 'category_slug' => 'demo-stationery', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'ملصقات', 'name_en' => 'Stickers', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-03-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-beauty-1', 'category_slug' => 'demo-beauty', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'أحمر شفاه', 'name_en' => 'Lipstick', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-04-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-beauty-2', 'category_slug' => 'demo-beauty', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'فرشاة مكياج', 'name_en' => 'Makeup Brush', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-04-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-beauty-3', 'category_slug' => 'demo-beauty', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'مرآة صغيرة', 'name_en' => 'Pocket Mirror', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-04-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-home-1', 'category_slug' => 'demo-home', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'منظم أدراج', 'name_en' => 'Drawer Organizer', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-05-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-home-2', 'category_slug' => 'demo-home', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'علاقة ملابس', 'name_en' => 'Clothes Hanger', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-05-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-home-3', 'category_slug' => 'demo-home', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'قطعة تنظيف', 'name_en' => 'Cleaning Cloth', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-05-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-kitchen-1', 'category_slug' => 'demo-kitchen', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'كوب', 'name_en' => 'Mug', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-06-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-kitchen-2', 'category_slug' => 'demo-kitchen', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'ملعقة سيليكون', 'name_en' => 'Silicone Spoon', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-06-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-kitchen-3', 'category_slug' => 'demo-kitchen', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'علبة حفظ', 'name_en' => 'Storage Container', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-06-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-toys-1', 'category_slug' => 'demo-toys', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'كرة صغيرة', 'name_en' => 'Mini Ball', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-07-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-toys-2', 'category_slug' => 'demo-toys', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'مكعبات', 'name_en' => 'Building Blocks', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-07-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-toys-3', 'category_slug' => 'demo-toys', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'لعبة ألغاز', 'name_en' => 'Puzzle', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-07-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-accessories-1', 'category_slug' => 'demo-accessories', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'مشبك شعر', 'name_en' => 'Hair Clip', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-08-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-accessories-2', 'category_slug' => 'demo-accessories', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'سوار', 'name_en' => 'Bracelet', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-08-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-accessories-3', 'category_slug' => 'demo-accessories', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'ميدالية مفاتيح', 'name_en' => 'Keychain', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-08-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-electronics-1', 'category_slug' => 'demo-electronics', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'حامل جوال', 'name_en' => 'Phone Stand', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-09-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-electronics-2', 'category_slug' => 'demo-electronics', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'منظم كابلات', 'name_en' => 'Cable Organizer', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-09-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-electronics-3', 'category_slug' => 'demo-electronics', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'غطاء سماعة', 'name_en' => 'Earbud Case', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-09-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-personal-care-1', 'category_slug' => 'demo-personal-care', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'مشط', 'name_en' => 'Comb', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-10-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-personal-care-2', 'category_slug' => 'demo-personal-care', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'صابون', 'name_en' => 'Soap', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-10-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-personal-care-3', 'category_slug' => 'demo-personal-care', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'إسفنجة استحمام', 'name_en' => 'Bath Sponge', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-10-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-gifts-1', 'category_slug' => 'demo-gifts', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'كيس هدية', 'name_en' => 'Gift Bag', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-11-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-gifts-2', 'category_slug' => 'demo-gifts', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'بطاقة تهنئة', 'name_en' => 'Greeting Card', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-11-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-gifts-3', 'category_slug' => 'demo-gifts', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'ورق تغليف', 'name_en' => 'Wrapping Paper', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-11-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-cleaning-1', 'category_slug' => 'demo-cleaning', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'فرشاة تنظيف', 'name_en' => 'Cleaning Brush', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-12-1', 'image' => null, 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-cleaning-2', 'category_slug' => 'demo-cleaning', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'إسفنجة تنظيف', 'name_en' => 'Cleaning Sponge', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-12-2', 'image' => null, 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-cleaning-3', 'category_slug' => 'demo-cleaning', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'قفازات', 'name_en' => 'Gloves', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-12-3', 'image' => null, 'is_active' => true, 'is_featured' => false],
        ];
    }

    private function sliders(): array
    {
        return [
            ['title_ar' => 'كل شيء بـ 5.75 ريال', 'title_en' => 'Everything at SAR 5.75', 'description_ar' => 'اكتشف منتجاتنا بسعر واحد.', 'description_en' => 'Explore our products at one fixed price.', 'image' => null, 'button_text_ar' => 'تسوق الآن', 'button_text_en' => 'Shop now', 'link' => '/products', 'sort_order' => 1, 'is_active' => true],
            ['title_ar' => 'احتياجاتك اليومية', 'title_en' => 'Everyday essentials', 'description_ar' => 'خيارات للمنزل والمطبخ والعناية.', 'description_en' => 'Find home, kitchen and personal care essentials.', 'image' => null, 'button_text_ar' => 'تسوق الآن', 'button_text_en' => 'Shop now', 'link' => '/products', 'sort_order' => 2, 'is_active' => true],
            ['title_ar' => 'اكتشف أقسامنا', 'title_en' => 'Explore our categories', 'description_ar' => 'تصفح الأقسام واختر ما يناسبك.', 'description_en' => 'Browse categories and find your favorites.', 'image' => null, 'button_text_ar' => 'تسوق الآن', 'button_text_en' => 'Shop now', 'link' => '/products', 'sort_order' => 3, 'is_active' => true],
        ];
    }

}
