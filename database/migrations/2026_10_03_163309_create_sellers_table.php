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
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('name_ar');
            $table->string('name_en');

            $table->string('slug')->unique();

            $table->string('logo')->nullable();

            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            $table->enum('status', [
                'pending',
                'approved',
                'rejected',
                'suspended',
            ])->default('pending');

            $table->decimal('commission_percentage', 5, 2)
                ->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sellers');
    }
};
