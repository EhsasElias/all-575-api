<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('courier_assignments')->cascadeOnDelete();
            $table->foreignId('courier_profile_id')->constrained('courier_profiles')->restrictOnDelete();
            $table->string('reason', 32);
            $table->text('notes')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();
            $table->index(['assignment_id', 'attempted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_delivery_attempts');
    }
};
