<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('seller_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('name_ar');
            $table->string('name_en');

            $table->string('slug')->unique();

            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            // Your business model: fixed selling price
            $table->decimal('price', 8, 2)->default(5.75);

            // What the seller receives / product cost
            $table->decimal('cost_price', 8, 2)->nullable();

            $table->unsignedInteger('stock')->default(0);

            $table->string('sku')->nullable()->unique();

            $table->string('image')->nullable();

            $table->boolean('is_active')->default(true);

            $table->boolean('is_featured')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
