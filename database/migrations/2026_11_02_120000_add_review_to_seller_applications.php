<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('seller_applications', function (Blueprint $table): void {
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seller_applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('seller_id');
            $table->dropColumn(['review_note', 'reviewed_at']);
        });
    }
};
