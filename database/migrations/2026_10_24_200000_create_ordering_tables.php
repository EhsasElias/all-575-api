<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_zone_id')->constrained()->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('booked')->default(0);
            $table->timestamps();

            $table->unique(['service_zone_id', 'starts_at']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('number')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // Order, payment, delivery and refund each have their own status; this one is the order's only.
            $table->string('status', 20);
            $table->string('idempotency_key', 64);
            $table->char('quote_id', 26);

            // The address as it was at purchase time. Later edits to the address book never rewrite history.
            $table->string('recipient_name', 100);
            $table->string('recipient_phone', 16);
            $table->string('region', 80);
            $table->string('city', 80);
            $table->string('district', 80);
            $table->string('street', 120);
            $table->char('building_number', 4);
            $table->char('additional_number', 4);
            $table->char('postal_code', 5);
            $table->char('short_address', 8)->nullable();
            $table->string('unit', 20)->nullable();
            $table->string('floor', 20)->nullable();
            $table->string('delivery_notes', 300)->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->foreignId('service_zone_id')->constrained()->restrictOnDelete();

            $table->foreignId('delivery_slot_id')->nullable()->constrained('delivery_slots')->nullOnDelete();
            $table->timestamp('slot_starts_at')->nullable();
            $table->timestamp('slot_ends_at')->nullable();

            $table->string('currency', 3);
            $table->unsignedInteger('subtotal_halalas');
            $table->unsignedInteger('discount_halalas');
            $table->unsignedInteger('delivery_fee_halalas');
            $table->unsignedInteger('total_halalas');
            $table->unsignedInteger('vat_halalas');
            $table->json('vat_breakdown');
            $table->string('promo_code')->nullable();
            $table->string('customer_notes', 500)->nullable();

            $table->timestamp('placed_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 40)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'placed_at']);
            $table->index('status');
        });

        Schema::create('suborders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('subtotal_halalas');
            $table->unsignedInteger('discount_halalas');
            $table->timestamp('eta_at')->nullable();
            $table->string('eta_reason', 120)->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('suborder_id')->constrained('suborders')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_slug');
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('variant_name_ar')->nullable();
            $table->string('variant_name_en')->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('unit_price_halalas');
            $table->unsignedInteger('line_price_halalas');
            $table->unsignedInteger('discount_halalas');
            $table->unsignedInteger('line_total_halalas');
            $table->string('vat_class', 16);
            $table->unsignedSmallInteger('vat_rate_basis_points');
            $table->timestamps();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('suborder_id')->nullable()->constrained('suborders')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('actor', 16);
            $table->string('note', 300)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'id']);
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_stock_id')->constrained('branch_stock')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->string('status', 12)->default('held');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('method', 12);
            $table->string('status', 12);
            $table->unsignedInteger('amount_halalas');
            $table->string('currency', 3);
            $table->string('provider_reference')->nullable()->unique();
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('event_id');
            $table->string('type', 40);
            $table->json('payload');
            $table->string('outcome', 40)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // The same provider callback delivered twice is recognised and ignored.
            $table->unique(['provider', 'event_id']);
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12);
            $table->unsignedInteger('amount_halalas');
            $table->string('reason', 40);
            $table->string('provider_reference')->nullable()->unique();
            $table->string('credit_note_number')->nullable()->unique();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('suborders');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('delivery_slots');
    }
};
