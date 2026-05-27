<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_embeddings', function (Blueprint $table) {
            $table->string('title')->nullable()->after('dimensions');
            $table->date('created_date')->nullable()->after('title');
            $table->unsignedBigInteger('correspondent_id')->nullable()->index()->after('created_date');
            $table->unsignedBigInteger('document_type_id')->nullable()->index()->after('correspondent_id');
            $table->unsignedBigInteger('storage_path_id')->nullable()->index()->after('document_type_id');
            $table->json('tags_json')->nullable()->after('storage_path_id');
            $table->boolean('trusted_for_context')->default(false)->index()->after('tags_json');
            $table->string('paperless_modified')->nullable()->index()->after('trusted_for_context');

            $table->index(['trusted_for_context', 'embedding_model', 'dimensions'], 'document_embeddings_context_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('document_embeddings', function (Blueprint $table) {
            $table->dropIndex('document_embeddings_context_lookup_index');
            $table->dropColumn([
                'title',
                'created_date',
                'correspondent_id',
                'document_type_id',
                'storage_path_id',
                'tags_json',
                'trusted_for_context',
                'paperless_modified',
            ]);
        });
    }
};
