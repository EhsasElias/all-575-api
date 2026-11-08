<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_category_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('seller_id')->constrained('sellers');
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('parent_id')->nullable()->constrained('categories');
            $table->string('name_ar', 180);
            $table->string('name_en', 180);
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['seller_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_category_requests');
    }
};
