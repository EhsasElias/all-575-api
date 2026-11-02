<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_refund_allocations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('refund_id');
            $table->ulid('suborder_id');
            $table->foreignId('seller_id')->constrained('sellers')->restrictOnDelete();
            $table->unsignedInteger('amount_halalas');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['refund_id', 'suborder_id']);
            $table->index(['seller_id', 'approved_at']);
            $table->foreign('refund_id')->references('id')->on('refunds')->restrictOnDelete();
            $table->foreign('suborder_id')->references('id')->on('suborders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_refund_allocations');
    }
};
