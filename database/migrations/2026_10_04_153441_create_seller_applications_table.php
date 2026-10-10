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
        Schema::create('seller_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('seller_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('store_name_ar');
            $table->string('store_name_en');

            $table->string('phone', 50);
            $table->string('email')->nullable();

            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();

            $table->string('status')
                ->default('pending');

            $table->text('admin_note')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index([
                'user_id',
                'status',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seller_applications');
    }
};
