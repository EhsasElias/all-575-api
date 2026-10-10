<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
  public function up(): void
{
    Schema::create('sliders', function (Blueprint $table) {
        $table->id();

        $table->string('title_ar');
        $table->string('title_en');

        $table->text('description_ar')->nullable();
        $table->text('description_en')->nullable();

        $table->string('image')->nullable();

        $table->string('button_text_ar')->nullable();
        $table->string('button_text_en')->nullable();

        $table->string('link')->nullable();

        $table->unsignedInteger('sort_order')->default(0);

        $table->boolean('is_active')->default(true);

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sliders');
    }
};
