<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_cases', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('order_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('submitted');
            $table->foreignId('order_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('subject', 120);
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['user_id', 'order_id', 'created_at']);
        });
        Schema::create('customer_case_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('customer_case_id')->constrained()->cascadeOnDelete();
            $table->string('author', 20);
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_case_messages');
        Schema::dropIfExists('customer_cases');
    }
};
