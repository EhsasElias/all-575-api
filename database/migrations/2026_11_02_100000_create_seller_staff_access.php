<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_staff_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnDelete();
            $table->string('role', 20);
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'seller_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_staff_access');
    }
};
