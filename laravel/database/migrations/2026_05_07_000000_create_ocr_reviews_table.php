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
        Schema::create('ocr_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('paperless_document_id')->index();
            $table->longText('original_content');
            $table->longText('ocr_content');
            $table->longText('approved_content')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('write_back_error')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('written_back_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ocr_reviews');
    }
};
