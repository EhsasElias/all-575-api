<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_staff_access', function (Blueprint $table): void {
            $table->string('employee_number', 24)->nullable()->after('user_id');
        });

        // Backfill every existing membership, including inactive members and owners.
        // Numbers are independent of account IDs; sequence is local to each store.
        $sellerIds = DB::table('seller_staff_access')->distinct()->orderBy('seller_id')->pluck('seller_id');
        foreach ($sellerIds as $sellerId) {
            $sequence = 0;
            DB::table('seller_staff_access')->where('seller_id', $sellerId)
                ->orderBy('id')->select('id')->chunk(200, function ($members) use (&$sequence): void {
                    foreach ($members as $member) {
                        ++$sequence;
                        DB::table('seller_staff_access')->where('id', $member->id)
                            ->update(['employee_number' => sprintf('EMP-%06d', $sequence)]);
                    }
                });
        }

        Schema::table('seller_staff_access', function (Blueprint $table): void {
            $table->unique(['seller_id', 'employee_number'], 'seller_staff_employee_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('seller_staff_access', function (Blueprint $table): void {
            $table->dropUnique('seller_staff_employee_number_unique');
            $table->dropColumn('employee_number');
        });
    }
};
