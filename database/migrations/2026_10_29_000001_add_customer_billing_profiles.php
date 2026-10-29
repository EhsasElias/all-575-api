<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_billing_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->string('buyer_type')->default('individual');
            $table->string('company_name')->nullable();
            $table->string('registration_number', 10)->nullable();
            $table->string('vat_number', 15)->nullable();
            $table->string('billing_address', 500)->nullable();
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('billing_details')->nullable();
            $table->string('purchase_order_reference', 80)->nullable();
        });
        Schema::create('customer_saved_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('product_id')->constrained('products');
            $table->timestamps();
            $table->unique(['user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_saved_products');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['billing_details', 'purchase_order_reference']));
        Schema::dropIfExists('customer_billing_profiles');
    }
};
