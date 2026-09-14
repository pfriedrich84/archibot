<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_runs', function (Blueprint $table): void {
            $table->string('orchestration_driver')->nullable()->after('trigger_source')->index();
            $table->string('temporal_workflow_id')->nullable()->after('orchestration_driver')->unique();
        });

        Schema::create('document_observations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('paperless_document_id')->index();
            $table->timestamp('paperless_modified')->nullable();
            $table->string('content_hash')->nullable();
            $table->string('version_key', 64);
            $table->string('source');
            $table->foreignId('source_command_id')->nullable()->constrained('commands')->nullOnDelete();
            $table->foreignId('pipeline_run_id')->nullable()->constrained('pipeline_runs')->nullOnDelete();
            $table->timestamp('observed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['paperless_document_id', 'version_key']);
            $table->index(['source', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_observations');

        Schema::table('pipeline_runs', function (Blueprint $table): void {
            $table->dropUnique(['temporal_workflow_id']);
            $table->dropIndex(['orchestration_driver']);
            $table->dropColumn(['orchestration_driver', 'temporal_workflow_id']);
        });
    }
};
