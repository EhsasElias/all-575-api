<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_application_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->string('document_type', 32);
            $table->string('file_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();

            $table->unique(['application_id', 'document_type']);
            $table->index('application_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_application_documents');
    }
};
