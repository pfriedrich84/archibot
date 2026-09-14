<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporal_document_phase_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_run_id')->constrained('pipeline_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('paperless_document_id')->index();
            $table->unsignedBigInteger('cycle')->nullable()->index();
            $table->string('phase')->default('registered')->index();
            $table->string('status')->default('waiting')->index();
            $table->string('configuration_revision')->nullable();
            $table->json('model_configuration')->nullable();
            $table->json('document_snapshot')->nullable();
            $table->json('classification_result')->nullable();
            $table->longText('raw_response')->nullable();
            $table->json('context_document_ids')->nullable();
            $table->json('judge_result')->nullable();
            $table->string('judge_verdict')->nullable();
            $table->text('judge_reasoning')->nullable();
            $table->longText('original_proposed_json')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(
                ['pipeline_run_id', 'cycle', 'phase'],
                'temporal_document_cycle_phase_unique',
            );
        });

        Schema::create('temporal_model_phase_states', function (Blueprint $table): void {
            $table->id();
            $table->string('scheduler_workflow_id');
            $table->unsignedBigInteger('cycle');
            $table->string('phase');
            $table->string('status')->index();
            $table->string('model_id')->nullable();
            $table->string('configuration_revision')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['scheduler_workflow_id', 'cycle', 'phase'], 'temporal_model_phase_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporal_model_phase_states');
        Schema::dropIfExists('temporal_document_phase_states');
    }
};
