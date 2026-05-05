<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_suggestions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('paperless_document_id')->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedTinyInteger('confidence')->nullable();
            $table->text('reasoning')->nullable();

            $table->string('original_title')->nullable();
            $table->date('original_date')->nullable();
            $table->unsignedBigInteger('original_correspondent_id')->nullable();
            $table->unsignedBigInteger('original_document_type_id')->nullable();
            $table->unsignedBigInteger('original_storage_path_id')->nullable();
            $table->json('original_tags')->nullable();

            $table->string('proposed_title')->nullable();
            $table->date('proposed_date')->nullable();
            $table->string('proposed_correspondent_name')->nullable();
            $table->unsignedBigInteger('proposed_correspondent_id')->nullable();
            $table->string('proposed_document_type_name')->nullable();
            $table->unsignedBigInteger('proposed_document_type_id')->nullable();
            $table->string('proposed_storage_path_name')->nullable();
            $table->unsignedBigInteger('proposed_storage_path_id')->nullable();
            $table->json('proposed_tags')->nullable();

            $table->json('context_documents')->nullable();
            $table->json('raw_response')->nullable();
            $table->string('judge_verdict')->nullable();
            $table->text('judge_reasoning')->nullable();
            $table->json('original_proposed_snapshot')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_suggestions');
    }
};
