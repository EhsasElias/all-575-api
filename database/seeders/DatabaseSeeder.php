<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->call(All575CategorySeeder::class);

            // Create a configured admin only when absent; preserve existing credentials.
            $adminName = env('ADMIN_NAME', 'System Admin');
            $adminEmail = env('ADMIN_EMAIL');
            $adminPassword = env('ADMIN_PASSWORD');

            if ($adminEmail && $adminPassword) {
                User::firstOrCreate(
                    ['email' => strtolower(trim($adminEmail))],
                    [
                        'name' => $adminName,
                        'password' => Hash::make($adminPassword),
                        'role' => 'admin',
                        'is_active' => true,
                    ]
                );
            }
        });
    }
}
