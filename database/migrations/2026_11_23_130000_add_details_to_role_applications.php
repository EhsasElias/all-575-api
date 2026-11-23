<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_applications', function (Blueprint $table): void {
            $table->string('contact_phone', 20)->nullable();
            $table->string('city', 120)->nullable();
            $table->unsignedTinyInteger('experience_years')->nullable();
            $table->string('qualification', 180)->nullable();
            $table->string('service_area', 180)->nullable();
            $table->string('vehicle_plate_number', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('role_applications', function (Blueprint $table): void {
            $table->dropColumn([
                'contact_phone', 'city', 'experience_years',
                'qualification', 'service_area', 'vehicle_plate_number',
            ]);
        });
    }
};
