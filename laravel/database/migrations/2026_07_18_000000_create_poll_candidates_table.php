<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poll_candidates', function (Blueprint $table) {
            $table->id();
            $table->uuid('candidate_id')->unique();
            $table->unsignedSmallInteger('protocol_version')->default(1);
            // Candidate rows are an audit/replay ledger. A command cannot be
            // deleted while its discovery evidence is retained.
            $table->foreignId('command_id')->constrained('commands')->restrictOnDelete();
            $table->unsignedBigInteger('paperless_document_id')->index();
            $table->string('discovered_modified')->nullable();
            $table->string('normalized_modified')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('normalized_content_state', 64)->nullable();
            $table->string('marker_disposition');
            $table->json('trigger_metadata');
            $table->string('idempotency_key', 64)->unique();
            $table->string('status')->default('ready')->index();
            $table->unsignedInteger('claim_attempts')->default(0);
            $table->unsignedInteger('claim_version')->default(0);
            $table->uuid('claim_token')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('starter_outcome')->nullable();
            $table->foreignId('pipeline_run_id')->nullable()->constrained('pipeline_runs')->nullOnDelete();
            $table->string('error_type')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['command_id', 'paperless_document_id']);
        });
    }

    public function down(): void
    {
        // WARNING: this intentionally removes the candidate audit/replay ledger.
        // Operators must stop producers/consumers and export poll_candidates
        // before rolling a persistent volume back to code without this schema.
        // Commands and Pipeline Runs remain durable and are not deleted here.
        Schema::dropIfExists('poll_candidates');
    }
};
