<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_case_responses', function (Blueprint $table): void {
            $table->id();
            $table->ulid('customer_case_id');
            $table->foreignId('seller_id');
            $table->foreignId('user_id');
            $table->string('position', 20);
            $table->text('explanation');
            $table->timestamps();

            $table->unique(['customer_case_id', 'seller_id']);
            $table->index(['seller_id', 'created_at']);
            $table->foreign('customer_case_id')->references('id')->on('customer_cases')->cascadeOnDelete();
            $table->foreign('seller_id')->references('id')->on('sellers');
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_case_responses');
    }
};
