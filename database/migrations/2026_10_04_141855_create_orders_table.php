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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            $table->string('order_number')->unique();

            $table->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('address_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // Address snapshot
            $table->string('customer_name');
            $table->string('customer_phone', 50);

            $table->string('city');
            $table->string('district')->nullable();
            $table->string('street')->nullable();
            $table->string('building')->nullable();

            $table->text('address_notes')->nullable();

            $table->decimal('subtotal', 10, 2);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('total', 10, 2);

            $table->string('currency', 10)->default('SAR');

            $table->string('payment_method')
                ->default('cash_on_delivery');

            $table->string('payment_status')
                ->default('pending');

            $table->string('status')
                ->default('pending');

            $table->text('customer_notes')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
