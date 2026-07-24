<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->string('paperless_version_checksum')->nullable()->after('paperless_document_id');
            $table->unsignedBigInteger('paperless_version_id')->nullable()->after('paperless_version_checksum');
            $table->string('staleness_reason')->nullable()->after('commit_command_id');
            $table->index(['paperless_document_id', 'paperless_version_id'], 'review_suggestions_document_version_index');
        });

        Schema::table('document_embeddings', function (Blueprint $table) {
            $table->unsignedBigInteger('paperless_version_id')->nullable()->after('paperless_document_id');
            $table->string('paperless_version_checksum')->nullable()->after('paperless_version_id');
            $table->date('document_date')->nullable()->after('title');
            $table->index(['paperless_document_id', 'paperless_version_id'], 'document_embeddings_document_version_index');
        });
    }

    public function down(): void
    {
        Schema::table('document_embeddings', function (Blueprint $table) {
            $table->dropIndex('document_embeddings_document_version_index');
            $table->dropColumn([
                'paperless_version_id',
                'paperless_version_checksum',
                'document_date',
            ]);
        });

        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->dropIndex('review_suggestions_document_version_index');
            $table->dropColumn([
                'paperless_version_id',
                'paperless_version_checksum',
                'staleness_reason',
            ]);
        });
    }
};
