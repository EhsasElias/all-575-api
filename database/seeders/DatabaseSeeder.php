<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        */

        $categories = [
            [
                'name_ar' => 'أطعمة',
                'name_en' => 'Food',
                'slug' => 'food',
                'sort_order' => 1,
            ],
            [
                'name_ar' => 'أدوية وصيدلية',
                'name_en' => 'Pharmacy',
                'slug' => 'pharmacy',
                'sort_order' => 2,
            ],
            [
                'name_ar' => 'اللحوم',
                'name_en' => 'Meat',
                'slug' => 'meat',
                'sort_order' => 3,
            ],
            [
                'name_ar' => 'البقالة',
                'name_en' => 'Groceries',
                'slug' => 'groceries',
                'sort_order' => 4,
            ],
            [
                'name_ar' => 'هدايا',
                'name_en' => 'Gifts',
                'slug' => 'gifts',
                'sort_order' => 5,
            ],
            [
                'name_ar' => 'مستلزمات الحيوانات الأليفة',
                'name_en' => 'Pet Supplies',
                'slug' => 'pet-supplies',
                'sort_order' => 6,
            ],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                [
                    'slug' => $category['slug'],
                ],
                [
                    'name_ar' => $category['name_ar'],
                    'name_en' => $category['name_en'],
                    'image' => null,
                    'sort_order' => $category['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Production Admin
        |--------------------------------------------------------------------------
        */

        $adminName = env(
            'ADMIN_NAME',
            'System Admin'
        );

        $adminEmail = env(
            'ADMIN_EMAIL'
        );

        $adminPassword = env(
            'ADMIN_PASSWORD'
        );

        if (
            $adminEmail
            && $adminPassword
        ) {
            User::updateOrCreate(
                [
                    'email' =>
                        strtolower(
                            trim($adminEmail)
                        ),
                ],
                [
                    'name' =>
                        $adminName,

                    'password' =>
                        Hash::make(
                            $adminPassword
                        ),

                    'role' =>
                        'admin',

                    'is_active' =>
                        true,
                ]
            );
        }
    }
}