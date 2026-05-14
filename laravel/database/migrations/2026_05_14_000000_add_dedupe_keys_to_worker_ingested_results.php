<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->string('dedupe_key', 64)->nullable()->after('source_suggestion_id')->unique();
        });

        Schema::table('ocr_reviews', function (Blueprint $table) {
            $table->string('dedupe_key', 64)->nullable()->after('paperless_document_id')->unique();
        });
    }

    public function down(): void
    {
        Schema::table('ocr_reviews', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });

        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn('dedupe_key');
        });
    }
};
