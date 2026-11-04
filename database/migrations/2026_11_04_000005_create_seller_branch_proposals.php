<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_branch_proposals', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id')->unique();
            $table->unsignedBigInteger('requested_by');
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_branch_proposals');
    }
};
