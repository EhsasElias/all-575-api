<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_approval_requests', function (Blueprint $table): void {
            $table->string('payload_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('finance_approval_requests', function (Blueprint $table): void {
            $table->dropColumn('payload_hash');
        });
    }
};
