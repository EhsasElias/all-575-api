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

        foreach ($this->products() as $product) {
            if (!is_file(public_path('images/all575/products/'.$product['slug'].'.png'))) {
                throw new RuntimeException('Copy public/images/all575 before running the seeders.');
            }
        }

        DB::transaction(function (): void {
            $categoryIds = app(All575CategorySeeder::class)->seedCategories();
            $sellerIds = [];
            foreach ($this->sellers() as $row) {
                $sellerIds[$row['slug']] = $this->insertIfMissing('sellers', ['slug' => $row['slug']], $row);
            }
            foreach ($this->products() as $row) {
                $row['category_id'] = $categoryIds[$row['category_slug']];
                $row['seller_id'] = $sellerIds[$row['seller_slug']];
                unset($row['category_slug'], $row['seller_slug']);
                $this->seedProduct($row);
            }
            foreach ($this->sliders() as $row) {
                // sliders has no slug column; use its bilingual titles as the seed identity.
                $this->insertIfMissing('sliders', [
                    'title_ar' => $row['title_ar'],
                    'title_en' => $row['title_en'],
                ], $row);
            }
            $this->createAccounts($sellerIds['demo-seller-1']);
        });

        $this->command?->info('All575 catalog updated: categories consolidated and sample product images corrected.');
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

    private function seedProduct(array $row): void
    {
        $existing = DB::table('products')->where('slug', $row['slug'])->lockForUpdate()->first();
        $skuMatch = DB::table('products')->where('sku', $row['sku'])->lockForUpdate()->first();
        if ($existing !== null && $skuMatch !== null && (int) $existing->id !== (int) $skuMatch->id) {
            throw new RuntimeException('Product slug and SKU identify different records: '.$row['slug']);
        }
        $existing ??= $skuMatch;
        if ($existing === null) {
            $this->insertIfMissing('products', ['slug' => $row['slug']], $row);
            return;
        }
        // Do not apply a sample image to a product repurposed by the manager.
        if (trim((string) $existing->name_ar) !== $row['name_ar']
            || trim((string) $existing->name_en) !== $row['name_en']) {
            $this->command?->warn('Skipped renamed product: '.$existing->slug.'; assign its image in the dashboard.');
            return;
        }
        DB::table('products')->where('id', $existing->id)->update([
            'image' => $row['image'],
            'category_id' => $row['category_id'],
            'updated_at' => now(),
        ]);
    }

    private function createAccounts(int $sellerId): void
    {
        foreach (['admin', 'seller', 'customer'] as $role) {
            $prefix = 'ALL575_'.strtoupper($role);
            // Service variables override cached config; config also supports .env files.
            $emailValue = getenv($prefix.'_EMAIL');
            $passwordValue = getenv($prefix.'_PASSWORD');
            $email = strtolower(trim((string) ($emailValue !== false ? $emailValue : config("all575.accounts.{$role}.email"))));
            $password = (string) ($passwordValue !== false ? $passwordValue : config("all575.accounts.{$role}.password"));

            if ($email === '' && $password === '' && app()->environment(['local', 'testing'])) {
                $email = "{$role}@all575.test";
                $password = 'All575Demo!2026';
            }
            if ($email === '' && $password === '') {
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
                throw new RuntimeException("Set {$prefix}_EMAIL and {$prefix}_PASSWORD (minimum 12 characters), or leave both unset.");
            }
            if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'role') || !Schema::hasColumn('users', 'is_active')) {
                throw new RuntimeException('Run the users and role/status migrations before creating accounts.');
            }
            $existing = DB::table('users')->where('email', $email)->first();
            if ($existing !== null && ($existing->role !== $role || !$existing->is_active)) {
                throw new RuntimeException("The requested {$role} email belongs to an existing account with another role or an inactive account. Choose another email.");
            }
            // Never reset an existing password or change an existing account's role.
            $userId = $existing !== null ? (int) $existing->id : $this->insertIfMissing('users', ['email' => $email], [
                'name' => 'All 575 '.ucfirst($role),
                'email' => $email,
                'password' => Hash::make($password),
                'role' => $role,
                'is_active' => true,
                'email_verified_at' => null,
            ]);

            if ($role === 'seller') {
                $seller = DB::table('sellers')->where('id', $sellerId)->first();
                $otherStore = DB::table('sellers')->where('user_id', $userId)->where('id', '!=', $sellerId)->exists();
                if (($seller->user_id !== null && (int) $seller->user_id !== $userId) || $otherStore) {
                    throw new RuntimeException('The demo store or requested seller account is already linked to another account or store.');
                }
                if ($seller->status !== 'approved' || !$seller->is_active) {
                    throw new RuntimeException('The demo seller store must be approved and active before linking the seller account.');
                }
                if ($seller->user_id === null) {
                    DB::table('sellers')->where('id', $sellerId)->update([
                        'user_id' => $userId,
                        'email' => $email,
                        'updated_at' => now(),
                    ]);
                }
            }
        }
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
            ['slug' => 'demo-food-1', 'category_slug' => 'food', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'بسكويت', 'name_en' => 'Biscuits', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-01-1', 'image' => asset('images/all575/products/demo-food-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-food-2', 'category_slug' => 'food', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'شوكولاتة', 'name_en' => 'Chocolate', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-01-2', 'image' => asset('images/all575/products/demo-food-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-food-3', 'category_slug' => 'food', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'شاي', 'name_en' => 'Tea', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-01-3', 'image' => asset('images/all575/products/demo-food-3.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-clothing-1', 'category_slug' => 'clothing', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'جوارب', 'name_en' => 'Socks', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-02-1', 'image' => asset('images/all575/products/demo-clothing-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-clothing-2', 'category_slug' => 'clothing', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'قبعة', 'name_en' => 'Cap', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-02-2', 'image' => asset('images/all575/products/demo-clothing-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-clothing-3', 'category_slug' => 'clothing', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'وشاح', 'name_en' => 'Scarf', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-02-3', 'image' => asset('images/all575/products/demo-clothing-3.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-stationery-1', 'category_slug' => 'stationery', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'دفتر', 'name_en' => 'Notebook', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-03-1', 'image' => asset('images/all575/products/demo-stationery-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-stationery-2', 'category_slug' => 'stationery', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'أقلام', 'name_en' => 'Pens', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-03-2', 'image' => asset('images/all575/products/demo-stationery-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-stationery-3', 'category_slug' => 'stationery', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'ملصقات', 'name_en' => 'Stickers', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-03-3', 'image' => asset('images/all575/products/demo-stationery-3.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-beauty-1', 'category_slug' => 'beauty', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'أحمر شفاه', 'name_en' => 'Lipstick', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-04-1', 'image' => asset('images/all575/products/demo-beauty-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-beauty-2', 'category_slug' => 'beauty', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'فرشاة مكياج', 'name_en' => 'Makeup Brush', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-04-2', 'image' => asset('images/all575/products/demo-beauty-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-beauty-3', 'category_slug' => 'beauty', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'مرآة صغيرة', 'name_en' => 'Pocket Mirror', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-04-3', 'image' => asset('images/all575/products/demo-beauty-3.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-home-1', 'category_slug' => 'home', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'منظم أدراج', 'name_en' => 'Drawer Organizer', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-05-1', 'image' => asset('images/all575/products/demo-home-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-home-2', 'category_slug' => 'home', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'علاقة ملابس', 'name_en' => 'Clothes Hanger', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-05-2', 'image' => asset('images/all575/products/demo-home-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-home-3', 'category_slug' => 'home', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'قطعة تنظيف', 'name_en' => 'Cleaning Cloth', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-05-3', 'image' => asset('images/all575/products/demo-home-3.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-kitchen-1', 'category_slug' => 'kitchen', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'كوب', 'name_en' => 'Mug', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-06-1', 'image' => asset('images/all575/products/demo-kitchen-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-kitchen-2', 'category_slug' => 'kitchen', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'ملعقة سيليكون', 'name_en' => 'Silicone Spoon', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-06-2', 'image' => asset('images/all575/products/demo-kitchen-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-kitchen-3', 'category_slug' => 'kitchen', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'علبة حفظ', 'name_en' => 'Storage Container', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-06-3', 'image' => asset('images/all575/products/demo-kitchen-3.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-toys-1', 'category_slug' => 'toys', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'كرة صغيرة', 'name_en' => 'Mini Ball', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-07-1', 'image' => asset('images/all575/products/demo-toys-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-toys-2', 'category_slug' => 'toys', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'مكعبات', 'name_en' => 'Building Blocks', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-07-2', 'image' => asset('images/all575/products/demo-toys-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-toys-3', 'category_slug' => 'toys', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'لعبة ألغاز', 'name_en' => 'Puzzle', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-07-3', 'image' => asset('images/all575/products/demo-toys-3.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-accessories-1', 'category_slug' => 'accessories', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'مشبك شعر', 'name_en' => 'Hair Clip', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-08-1', 'image' => asset('images/all575/products/demo-accessories-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-accessories-2', 'category_slug' => 'accessories', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'سوار', 'name_en' => 'Bracelet', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-08-2', 'image' => asset('images/all575/products/demo-accessories-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-accessories-3', 'category_slug' => 'accessories', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'ميدالية مفاتيح', 'name_en' => 'Keychain', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-08-3', 'image' => asset('images/all575/products/demo-accessories-3.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-electronics-1', 'category_slug' => 'electronics', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'حامل جوال', 'name_en' => 'Phone Stand', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-09-1', 'image' => asset('images/all575/products/demo-electronics-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-electronics-2', 'category_slug' => 'electronics', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'منظم كابلات', 'name_en' => 'Cable Organizer', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-09-2', 'image' => asset('images/all575/products/demo-electronics-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-electronics-3', 'category_slug' => 'electronics', 'seller_slug' => 'demo-seller-1', 'name_ar' => 'غطاء سماعة', 'name_en' => 'Earbud Case', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-09-3', 'image' => asset('images/all575/products/demo-electronics-3.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-personal-care-1', 'category_slug' => 'personal-care', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'مشط', 'name_en' => 'Comb', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-10-1', 'image' => asset('images/all575/products/demo-personal-care-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-personal-care-2', 'category_slug' => 'personal-care', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'صابون', 'name_en' => 'Soap', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-10-2', 'image' => asset('images/all575/products/demo-personal-care-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-personal-care-3', 'category_slug' => 'personal-care', 'seller_slug' => 'demo-seller-2', 'name_ar' => 'إسفنجة استحمام', 'name_en' => 'Bath Sponge', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-10-3', 'image' => asset('images/all575/products/demo-personal-care-3.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-gifts-1', 'category_slug' => 'gifts', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'كيس هدية', 'name_en' => 'Gift Bag', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-11-1', 'image' => asset('images/all575/products/demo-gifts-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-gifts-2', 'category_slug' => 'gifts', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'بطاقة تهنئة', 'name_en' => 'Greeting Card', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-11-2', 'image' => asset('images/all575/products/demo-gifts-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-gifts-3', 'category_slug' => 'gifts', 'seller_slug' => 'demo-seller-3', 'name_ar' => 'ورق تغليف', 'name_en' => 'Wrapping Paper', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-11-3', 'image' => asset('images/all575/products/demo-gifts-3.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-cleaning-1', 'category_slug' => 'cleaning', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'فرشاة تنظيف', 'name_en' => 'Cleaning Brush', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-12-1', 'image' => asset('images/all575/products/demo-cleaning-1.png'), 'is_active' => true, 'is_featured' => true],
            ['slug' => 'demo-cleaning-2', 'category_slug' => 'cleaning', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'إسفنجة تنظيف', 'name_en' => 'Cleaning Sponge', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-12-2', 'image' => asset('images/all575/products/demo-cleaning-2.png'), 'is_active' => true, 'is_featured' => false],
            ['slug' => 'demo-cleaning-3', 'category_slug' => 'cleaning', 'seller_slug' => 'demo-seller-4', 'name_ar' => 'قفازات', 'name_en' => 'Gloves', 'description_ar' => 'منتج تجريبي للاختبار بسعر 5.75 ريال.', 'description_en' => 'Sample product for testing, priced at SAR 5.75.', 'price' => '5.75', 'cost_price' => null, 'stock' => 100, 'sku' => 'DEMO575-12-3', 'image' => asset('images/all575/products/demo-cleaning-3.png'), 'is_active' => true, 'is_featured' => false],
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
