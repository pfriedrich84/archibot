<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('paperless');
            $table->string('event_type')->index();
            $table->unsignedBigInteger('paperless_document_id')->nullable()->index();
            $table->string('dedupe_key');
            $table->string('payload_hash', 64)->index();
            $table->json('raw_payload');
            $table->json('normalized_payload')->nullable();
            $table->json('headers')->nullable();
            $table->string('status')->default('received')->index();
            $table->string('request_id')->nullable()->index();
            $table->timestamp('received_at')->useCurrent()->index();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['source', 'dedupe_key']);
            $table->index(['status', 'received_at']);
        });

        Schema::create('commands', function (Blueprint $table) {
            $table->id();
            $table->string('type')->index();
            $table->string('status')->default('pending')->index();
            $table->json('payload')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('embedding_index_state', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending')->index();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('dimensions')->nullable();
            $table->string('content_scope')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('document_count')->default(0);
            $table->unsignedInteger('embedded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('pipeline_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('command_id')->nullable()->constrained('commands')->nullOnDelete();
            $table->foreignId('webhook_delivery_id')->nullable()->constrained('webhook_deliveries')->nullOnDelete();
            $table->string('type')->index();
            $table->string('status')->default('pending')->index();
            $table->string('scope')->nullable();
            $table->string('trigger_source')->index();
            $table->unsignedBigInteger('paperless_document_id')->nullable()->index();
            $table->timestamp('paperless_modified')->nullable();
            $table->string('content_hash')->nullable();
            $table->string('pipeline_dedupe_key')->nullable();
            $table->json('coalesced_sources')->nullable();
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedInteger('progress_done')->default(0);
            $table->unsignedInteger('progress_failed')->default(0);
            $table->unsignedInteger('progress_skipped')->default(0);
            $table->string('progress_current_phase')->nullable();
            $table->unsignedInteger('progress_phase_total')->default(0);
            $table->unsignedInteger('progress_phase_done')->default(0);
            $table->string('progress_message')->nullable();
            $table->timestamp('progress_updated_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('max_retries')->default(5);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_retry_at')->nullable();
            $table->string('retry_reason')->nullable();
            $table->string('retry_mode')->nullable();
            $table->foreignId('retry_of_run_id')->nullable()->constrained('pipeline_runs')->nullOnDelete();
            $table->boolean('reprocess_requested')->default(false)->index();
            $table->string('reprocess_reason')->nullable();
            $table->string('reprocess_mode')->nullable();
            $table->foreignId('reprocess_of_run_id')->nullable()->constrained('pipeline_runs')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['paperless_document_id', 'pipeline_dedupe_key']);
            $table->index(['status', 'updated_at']);
            $table->index(['trigger_source', 'created_at']);
        });

        Schema::create('pipeline_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_run_id')->nullable()->constrained('pipeline_runs')->nullOnDelete();
            $table->foreignId('webhook_delivery_id')->nullable()->constrained('webhook_deliveries')->nullOnDelete();
            $table->foreignId('command_id')->nullable()->constrained('commands')->nullOnDelete();
            $table->string('event_type')->index();
            $table->unsignedBigInteger('paperless_document_id')->nullable()->index();
            $table->string('level')->default('info')->index();
            $table->string('message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('actor_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_run_id')->nullable()->constrained('pipeline_runs')->nullOnDelete();
            $table->unsignedBigInteger('paperless_document_id')->nullable()->index();
            $table->string('actor_name')->index();
            $table->string('message_id')->nullable()->index();
            $table->string('queue_name')->nullable()->index();
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->string('worker_id')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('progress_total')->default(0);
            $table->unsignedInteger('progress_done')->default(0);
            $table->unsignedInteger('progress_failed')->default(0);
            $table->unsignedInteger('progress_skipped')->default(0);
            $table->string('progress_current_item')->nullable();
            $table->string('progress_message')->nullable();
            $table->timestamp('progress_updated_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_retry_at')->nullable();
            $table->string('retry_reason')->nullable();
            $table->string('retry_mode')->nullable();
            $table->string('error_type')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        Schema::create('pipeline_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_run_id')->constrained('pipeline_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('paperless_document_id')->nullable()->index();
            $table->string('item_type')->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempt')->default(0);
            $table->unsignedInteger('max_attempts')->default(5);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_retry_at')->nullable();
            $table->string('retry_reason')->nullable();
            $table->string('retry_mode')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['pipeline_run_id', 'status']);
        });

        Schema::create('llm_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_run_id')->nullable()->constrained('pipeline_runs')->nullOnDelete();
            $table->unsignedBigInteger('paperless_document_id')->nullable()->index();
            $table->string('provider')->index();
            $table->string('model')->index();
            $table->string('purpose')->index();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('error_type')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('document_embeddings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('paperless_document_id')->index();
            $table->string('content_hash')->index();
            $table->string('embedding_model')->index();
            $table->unsignedInteger('dimensions');
            if (Schema::getConnection()->getDriverName() !== 'pgsql') {
                $table->json('embedding')->nullable();
            }
            $table->timestamps();

            $table->unique(['paperless_document_id', 'content_hash', 'embedding_model', 'dimensions'], 'document_embeddings_dedupe_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE document_embeddings ADD COLUMN embedding vector');
            DB::statement('CREATE INDEX document_embeddings_embedding_hnsw ON document_embeddings USING hnsw (embedding vector_cosine_ops)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_embeddings');
        Schema::dropIfExists('llm_calls');
        Schema::dropIfExists('pipeline_items');
        Schema::dropIfExists('actor_executions');
        Schema::dropIfExists('pipeline_events');
        Schema::dropIfExists('pipeline_runs');
        Schema::dropIfExists('embedding_index_state');
        Schema::dropIfExists('commands');
        Schema::dropIfExists('webhook_deliveries');
    }
};
