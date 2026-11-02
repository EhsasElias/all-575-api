<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('operation', 60);
            $table->string('reference', 100);
            $table->string('status', 30)->default('pending_second_approval');
            $table->foreignId('first_approver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('second_approver_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('first_approved_at');
            $table->timestamp('second_approved_at')->nullable();
            $table->timestamps();
            $table->unique(['operation', 'reference']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_approval_requests');
    }
};
