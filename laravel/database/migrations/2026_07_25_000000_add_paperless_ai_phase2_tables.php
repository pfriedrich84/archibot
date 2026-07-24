<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paperless_ai_config_states', function (Blueprint $table) {
            $table->id();
            $table->json('desired_config')->nullable();
            $table->json('remote_config')->nullable();
            $table->json('drift_fields')->nullable();
            $table->string('sync_status')->default('not_synced')->index();
            $table->text('last_sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_remote_read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('paperless_workflow_states', function (Blueprint $table) {
            $table->id();
            $table->string('workflow_key')->unique();
            $table->unsignedBigInteger('paperless_workflow_id')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->boolean('auto_managed')->default(true);
            $table->boolean('enabled_desired')->default(false);
            $table->boolean('drift_detected')->default(false);
            $table->json('desired_definition')->nullable();
            $table->json('remote_definition')->nullable();
            $table->json('drift_fields')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_remote_read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('paperless_master_data_cases', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->index();
            $table->string('normalized_name')->index();
            $table->string('canonical_name')->nullable();
            $table->string('status')->default('pending')->index();
            $table->json('proposed_names')->nullable();
            $table->json('spelling_variants')->nullable();
            $table->json('similar_existing_entities')->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_observed_at')->nullable();
            $table->timestamp('last_observed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('mapped_paperless_id')->nullable();
            $table->string('decision_reason')->nullable();
            $table->string('sync_status')->nullable()->index();
            $table->text('last_sync_error')->nullable();
            $table->timestamp('suppressed_until')->nullable()->index();
            $table->timestamp('detail_retention_until')->nullable()->index();
            $table->timestamps();

            $table->unique(['entity_type', 'normalized_name'], 'paperless_master_data_cases_unique');
        });

        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->string('origin')->default('pipeline')->after('paperless_version_checksum')->index();
            $table->string('context_quality')->nullable()->after('context_documents');
            $table->unsignedInteger('context_document_count')->default(0)->after('context_quality');
            $table->foreignId('requested_by_user_id')->nullable()->after('created_by_user_id')->constrained('users')->nullOnDelete();
            $table->string('request_source')->nullable()->after('requested_by_user_id');
        });

        Schema::table('embedding_index_state', function (Blueprint $table) {
            $table->string('scope')->nullable()->after('content_scope')->index();
            $table->unsignedInteger('release_threshold')->default(0)->after('failed_count');
            $table->unsignedInteger('release_target_population')->default(0)->after('release_threshold');
            $table->timestamp('released_at')->nullable()->after('completed_at');
            $table->string('release_status')->default('pending')->after('status')->index();
        });

        Schema::table('commands', function (Blueprint $table) {
            $table->string('queue')->nullable()->after('type')->index();
            $table->unsignedTinyInteger('priority')->default(50)->after('queue')->index();
        });
    }

    public function down(): void
    {
        Schema::table('commands', function (Blueprint $table) {
            $table->dropColumn(['queue', 'priority']);
        });

        Schema::table('embedding_index_state', function (Blueprint $table) {
            $table->dropColumn(['scope', 'release_threshold', 'release_target_population', 'released_at', 'release_status']);
        });

        Schema::table('review_suggestions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropColumn(['origin', 'context_quality', 'context_document_count', 'request_source']);
        });

        Schema::dropIfExists('paperless_master_data_cases');
        Schema::dropIfExists('paperless_workflow_states');
        Schema::dropIfExists('paperless_ai_config_states');
    }
};
