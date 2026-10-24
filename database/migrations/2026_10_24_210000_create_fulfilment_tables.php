<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The code the customer reads out to the courier. Separate from the login OTP on purpose.
        Schema::create('delivery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('suborder_id')->unique()->constrained('suborders')->cascadeOnDelete();
            $table->text('code_encrypted');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts');
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('body', 1000)->nullable();
            $table->string('status', 12)->default('published');
            $table->timestamp('editable_until');
            $table->timestamps();

            // One review per product per purchase.
            $table->unique(['user_id', 'order_id', 'product_id']);
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('delivery_codes');
    }
};
