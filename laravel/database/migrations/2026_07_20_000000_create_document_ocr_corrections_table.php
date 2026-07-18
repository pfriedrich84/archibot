<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_ocr_corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('paperless_document_id')->unique();
            $table->text('corrected_content');
            $table->string('ocr_mode', 32);
            $table->unsignedInteger('num_corrections')->default(0);
            $table->timestampTz('corrected_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_ocr_corrections');
    }
};
