<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courier_assignments', function (Blueprint $table): void {
            $table->string('delivery_code_hash', 255)->nullable();
            $table->timestamp('delivery_code_expires_at')->nullable();
            $table->unsignedTinyInteger('delivery_attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courier_assignments', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_code_hash',
                'delivery_code_expires_at',
                'delivery_attempts',
                'delivered_at',
            ]);
        });
    }
};
