<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seller_applications') || ! Schema::hasColumn('seller_applications', 'iban')) {
            return;
        }

        Schema::table('seller_applications', function (Blueprint $table): void {
            // Encrypted Laravel payloads are longer than raw Saudi IBANs.
            $table->text('iban')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Do not shorten the column: encrypted IBAN values could be truncated.
    }
};
