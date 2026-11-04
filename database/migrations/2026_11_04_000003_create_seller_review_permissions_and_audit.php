<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_review_grants', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('seller_review_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('reviewer_id');
            $table->string('subject_type', 24);
            $table->unsignedBigInteger('subject_id');
            $table->string('previous_status', 32);
            $table->string('new_status', 32);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('seller_applications', function (Blueprint $table): void {
            $table->unsignedBigInteger('approved_seller_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('seller_applications', function (Blueprint $table): void {
            $table->dropUnique(['approved_seller_id']);
            $table->dropColumn('approved_seller_id');
        });
        Schema::dropIfExists('seller_review_audits');
        Schema::dropIfExists('seller_review_grants');
    }
};
