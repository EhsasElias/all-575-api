<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->unsignedInteger('radius_meters');
            $table->unsignedInteger('delivery_fee_halalas')->default(0);
            $table->unsignedInteger('free_delivery_threshold_halalas')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('logo_path')->nullable();
            $table->string('status', 16)->default('pending');
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_zone_id')->constrained()->restrictOnDelete();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('slug')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('image_path')->nullable();
            $table->string('vat_class', 16)->default('standard');
            $table->string('status', 16)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->decimal('rating_average', 2, 1)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamps();

            $table->index(['status', 'category_id']);
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->index();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('branch_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedInteger('on_hand')->default(0);
            // Held for unpaid orders. Available = on_hand - reserved, and reserved never exceeds on_hand.
            $table->unsignedInteger('reserved')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'variant_id']);
        });

        Schema::create('welcome_slides', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('position');
            $table->string('image_path');
            $table->string('title_ar');
            $table->string('title_en');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('welcome_slides');
        Schema::dropIfExists('branch_stock');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('sellers');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('service_zones');
    }
};
