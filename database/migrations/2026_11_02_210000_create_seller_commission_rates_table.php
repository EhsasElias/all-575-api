<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL can leave this table behind when an index creation fails.
        // Resume safely without dropping any existing commission data.
        if (! Schema::hasTable('seller_commission_rates')) {
            Schema::create('seller_commission_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->unsignedSmallInteger('rate_basis_points');
            $table->dateTime('effective_from');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['seller_id', 'effective_from']);
            $table->index(['seller_id', 'approved_at', 'effective_from'], 'seller_commission_approval_idx');
            });

            return;
        }

        // A previous deployment may have created the table and its unique key,
        // but failed before adding the long composite index.
        $required = ['seller_id', 'approved_at', 'effective_from'];
        if (! Schema::hasColumns('seller_commission_rates', $required)) {
            throw new \RuntimeException('Existing seller_commission_rates table has an unexpected schema; inspect it before migrating.');
        }

        $hasIndex = collect(Schema::getIndexes('seller_commission_rates'))
            ->contains(static fn (array $index): bool => ($index['columns'] ?? []) === $required);
        if (! $hasIndex) {
            Schema::table('seller_commission_rates', function (Blueprint $table): void {
                $table->index(['seller_id', 'approved_at', 'effective_from'], 'seller_commission_approval_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_commission_rates');
    }
};
