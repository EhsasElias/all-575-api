<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40)->nullable();
            $table->string('recipient_name', 100);
            $table->string('recipient_phone', 16);

            // Saudi National Address (SPL). The map pin below is stored separately: it is not proof of these.
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
            $table->foreignId('service_zone_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
