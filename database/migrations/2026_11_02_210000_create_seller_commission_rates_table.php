<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_commission_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->unsignedSmallInteger('rate_basis_points');
            $table->dateTime('effective_from');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['seller_id', 'effective_from']);
            $table->index(['seller_id', 'approved_at', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_commission_rates');
    }
};
