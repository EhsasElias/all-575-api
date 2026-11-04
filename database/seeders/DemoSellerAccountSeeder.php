<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\SellerStatus;
use App\Domain\Catalog\Models\Seller;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Local-only seller owner for exercising the workspace.
 *
 * This is NOT a seller-application approval operation. Real applicants must
 * still be approved through the authenticated, audited admin review flow.
 */
final class DemoSellerAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new LogicException('Demo seller accounts cannot be seeded in production.');
        }

        $email = Str::lower(trim((string) config('demo.seller_email', '')));
        if ($email === '') {
            $this->command?->warn('Set DEMO_SELLER_EMAIL in .env to seed a local seller account.');

            return;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new LogicException('DEMO_SELLER_EMAIL must be a valid email address.');
        }

        DB::transaction(function () use ($email): void {
            $seller = Seller::query()->where('slug', 'home-store')
                ->where('status', SellerStatus::Approved->value)->first();

            if ($seller === null) {
                throw new LogicException('Seed the demo catalog first: php artisan db:seed --class=DemoCatalogSeeder');
            }

            // Never grant seller ownership to an arbitrary pre-existing account.
            // Repeated runs are allowed only for our own seeded test identity.
            $user = User::query()->where('email', $email)->first();
            if ($user !== null) {
                $owned = DB::table('seller_staff_access')
                    ->where('user_id', $user->id)->where('seller_id', $seller->id)
                    ->where('role', 'owner')->whereNull('branch_id')->exists();

                if (! $owned) {
                    throw new LogicException('DEMO_SELLER_EMAIL belongs to an existing account; choose another email.');
                }

                return;
            }

            $user = User::query()->create([
                'name' => 'Local Demo Seller',
                'email' => $email,
                // Login still requires the app's normal email verification/OTP workflow.
                'email_verified_at' => null,
                'phone' => null,
                'phone_verified_at' => null,
                'locale' => 'ar',
                'is_active' => true,
            ]);

            DB::table('seller_staff_access')->insert([
                'user_id' => $user->id,
                'seller_id' => $seller->id,
                'branch_id' => null,
                'role' => 'owner',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->command?->info('Local seller account prepared. Sign in with '.$email.' using the regular verification flow.');
    }
}
