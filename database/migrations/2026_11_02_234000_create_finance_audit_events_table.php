<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('approval_request_id')->constrained('finance_approval_requests')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('payload_hash', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->index(['approval_request_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_audit_events');
    }
};
