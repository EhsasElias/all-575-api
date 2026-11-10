<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_admin_access', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
            $table->boolean('is_active')->default(false);
            $table->boolean('can_view_orders')->default(true);
            $table->boolean('can_view_inventory')->default(true);
            $table->boolean('can_review_sellers')->default(false);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'seller_id']);
            $table->index(['seller_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_access');
    }
};
