<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('requested_role', 20);
            $table->string('status', 20)->default('pending');
            $table->string('vehicle_type', 30)->nullable();
            $table->text('motivation');
            $table->timestamps();
            $table->unique(['user_id', 'requested_role']);
            $table->index(['requested_role', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_applications');
    }
};
