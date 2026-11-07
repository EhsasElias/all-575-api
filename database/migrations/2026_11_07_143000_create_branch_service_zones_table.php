<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('branch_service_zones', function (Blueprint $table): void {
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('service_zone_id')->constrained('service_zones')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['branch_id', 'service_zone_id']);
        });

        // Preserve existing branch coverage on upgrade.
        DB::table('branches')->select(['id', 'service_zone_id'])->orderBy('id')
            ->chunk(200, function ($branches): void {
                $now = now();
                DB::table('branch_service_zones')->insert(
                    $branches->map(fn ($branch): array => [
                        'branch_id' => $branch->id,
                        'service_zone_id' => $branch->service_zone_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all()
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_service_zones');
    }
};
