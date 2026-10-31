<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 32)->change();
        });
    }

    public function down(): void
    {
        // Intentionally do not shrink this column,
        // because existing values may exceed 12 characters.
    }
};
