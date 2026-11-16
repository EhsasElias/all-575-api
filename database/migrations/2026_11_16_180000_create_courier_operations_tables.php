<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('courier_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('service_zone_id')->constrained('service_zones')->restrictOnDelete();
            $table->string('vehicle_type', 30);
            $table->string('approval_status', 16)->default('pending');
            $table->boolean('is_online')->default(false);
            $table->timestamps();
            $table->index(['service_zone_id', 'approval_status', 'is_online']);
        });
        Schema::create('courier_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('suborder_id')->unique()->constrained('suborders')->cascadeOnDelete();
            $table->foreignId('courier_profile_id')->constrained('courier_profiles')->restrictOnDelete();
            $table->string('status', 20)->default('offered');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->unsignedTinyInteger('pickup_attempts')->default(0);
            $table->string('pickup_code_hash', 255)->nullable();
            $table->text('pickup_code_encrypted')->nullable();
            $table->timestamp('pickup_code_expires_at')->nullable();
            $table->unsignedSmallInteger('parcel_count')->nullable();
            $table->timestamps();
            $table->index(['courier_profile_id', 'status']);
        });
        Schema::create('courier_assignment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('courier_assignments')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('courier_profile_id')->nullable()->constrained('courier_profiles')->nullOnDelete();
            $table->string('action', 30);
            $table->string('reason', 300)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('delivery_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('suborder_id')->constrained('suborders')->cascadeOnDelete();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24);
            $table->string('status', 20)->default('open');
            $table->text('description');
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['suborder_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_incidents');
        Schema::dropIfExists('courier_assignment_events');
        Schema::dropIfExists('courier_assignments');
        Schema::dropIfExists('courier_profiles');
    }
};
