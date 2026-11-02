<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('seller_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('legal_name', 180);
            $table->string('commercial_registration', 30);
            $table->string('national_unified_number', 20)->nullable();
            $table->string('vat_number', 30)->nullable();
            $table->string('contact_name', 120);
            $table->string('contact_email', 180);
            $table->string('contact_phone', 20);
            $table->string('city', 100);
            $table->string('status', 24)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_applications');
    }
};
