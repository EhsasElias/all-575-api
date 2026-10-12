<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Shared category catalog: both seeders use these same canonical slugs. */
class All575CategorySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedCategories();
    }

    public function seedCategories(): array
    {
        foreach ($this->categories() as $row) {
            if (!is_file(public_path('images/all575/categories/'.$row['slug'].'.png'))) {
                throw new RuntimeException('Copy public/images/all575 before running the seeders.');
            }
        }
        return DB::transaction(function (): array {
            $ids = [];
            foreach ($this->categories() as $row) {
                $slug = $row['slug'];
                $legacySlug = 'demo-'.$slug;
                $canonical = DB::table('categories')->where('slug', $slug)->lockForUpdate()->first();
                $legacy = DB::table('categories')->where('slug', $legacySlug)->lockForUpdate()->first();

                if ($canonical === null && $legacy !== null) {
                    // Reuse its ID so existing links keep working.
                    DB::table('categories')->where('id', $legacy->id)->update([
                        'slug' => $slug, 'updated_at' => now(),
                    ]);
                    $canonical = $legacy;
                }
                if ($canonical === null) {
                    $id = DB::table('categories')->insertGetId(array_merge($row, [
                        'image' => asset('images/all575/categories/'.$slug.'.png'),
                        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]));
                } else {
                    $id = (int) $canonical->id;
                    DB::table('categories')->where('id', $id)->update([
                        'image' => asset('images/all575/categories/'.$slug.'.png'),
                        'sort_order' => $row['sort_order'], 'is_active' => true, 'updated_at' => now(),
                    ]);
                }
                if ($legacy !== null && (int) $legacy->id !== (int) $id) {
                    // Move products first. Never delete a category with cascadeOnDelete.
                    DB::table('products')->where('category_id', $legacy->id)->update([
                        'category_id' => $id, 'updated_at' => now(),
                    ]);
                    DB::table('categories')->where('id', $legacy->id)->update([
                        'is_active' => false, 'updated_at' => now(),
                    ]);
                }
                $ids[$slug] = (int) $id;
            }
            DB::table('categories')->whereIn('slug', ['pharmacy', 'demo-pharmacy'])->update([
                'is_active' => false, 'updated_at' => now(),
            ]);
            return $ids;
        });
    }

    private function categories(): array
    {
        return [
            ['slug' => 'food', 'name_ar' => 'أطعمة', 'name_en' => 'Food', 'sort_order' => 1],
            ['slug' => 'meat', 'name_ar' => 'اللحوم', 'name_en' => 'Meat', 'sort_order' => 2],
            ['slug' => 'groceries', 'name_ar' => 'البقالة', 'name_en' => 'Groceries', 'sort_order' => 3],
            ['slug' => 'gifts', 'name_ar' => 'هدايا', 'name_en' => 'Gifts', 'sort_order' => 4],
            ['slug' => 'pet-supplies', 'name_ar' => 'مستلزمات الحيوانات الأليفة', 'name_en' => 'Pet Supplies', 'sort_order' => 5],
            ['slug' => 'accessories', 'name_ar' => 'الإكسسوارات', 'name_en' => 'Accessories', 'sort_order' => 6],
            ['slug' => 'clothing', 'name_ar' => 'الملابس', 'name_en' => 'Clothing', 'sort_order' => 7],
            ['slug' => 'stationery', 'name_ar' => 'القرطاسية', 'name_en' => 'Stationery', 'sort_order' => 8],
            ['slug' => 'beauty', 'name_ar' => 'التجميل', 'name_en' => 'Beauty', 'sort_order' => 9],
            ['slug' => 'home', 'name_ar' => 'المنزل', 'name_en' => 'Home', 'sort_order' => 10],
            ['slug' => 'kitchen', 'name_ar' => 'المطبخ', 'name_en' => 'Kitchen', 'sort_order' => 11],
            ['slug' => 'toys', 'name_ar' => 'الألعاب', 'name_en' => 'Toys', 'sort_order' => 12],
            ['slug' => 'electronics', 'name_ar' => 'إكسسوارات إلكترونية', 'name_en' => 'Electronics Accessories', 'sort_order' => 13],
            ['slug' => 'personal-care', 'name_ar' => 'العناية الشخصية', 'name_en' => 'Personal Care', 'sort_order' => 14],
            ['slug' => 'cleaning', 'name_ar' => 'التنظيف', 'name_en' => 'Cleaning', 'sort_order' => 15],
        ];
    }
}
