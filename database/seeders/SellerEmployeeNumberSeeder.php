<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent number backfill for existing demo/staging team memberships.
 * Never changes a number already assigned; does not add accounts or permissions.
 */
final class SellerEmployeeNumberSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasColumn('seller_staff_access', 'employee_number')) {
            throw new \RuntimeException('Run php artisan migrate before this seeder.');
        }

        $sellerIds = DB::table('seller_staff_access')->whereNull('employee_number')
            ->distinct()->orderBy('seller_id')->pluck('seller_id');

        $assigned = 0;
        foreach ($sellerIds as $sellerId) {
            DB::transaction(function () use ($sellerId, &$assigned): void {
                DB::table('sellers')->where('id', $sellerId)->lockForUpdate()->first();

                $last = DB::table('seller_staff_access')->where('seller_id', $sellerId)
                    ->whereNotNull('employee_number')->orderByDesc('employee_number')
                    ->value('employee_number');
                $sequence = $last ? (int) substr((string) $last, 4) : 0;

                $missing = DB::table('seller_staff_access')->where('seller_id', $sellerId)
                    ->whereNull('employee_number')->orderBy('id')->pluck('id');

                foreach ($missing as $membershipId) {
                    if ($sequence >= 999999) {
                        throw new \RuntimeException('Employee numbering limit reached for seller '.$sellerId);
                    }
                    DB::table('seller_staff_access')->where('id', $membershipId)
                        ->whereNull('employee_number')
                        ->update(['employee_number' => sprintf('EMP-%06d', ++$sequence)]);
                    ++$assigned;
                }
            }, 3);
        }

        $this->command?->info("Assigned {$assigned} missing employee numbers; existing numbers unchanged.");
    }
}
