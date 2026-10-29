<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users');
            $table->string('request_key', 64);
            $table->string('kind');
            $table->string('status')->default('submitted');
            $table->string('subject', 200);
            $table->text('description');
            $table->text('reply')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_requests');
    }
};
