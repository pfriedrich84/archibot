<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArchibotResetService
{
    /** @return array<int, string> */
    public function reset(bool $includeConfig = false): array
    {
        $operations = $this->operationalDeletes();
        if ($includeConfig) {
            $operations = [...$operations, ...$this->configurationDeletes()];
        }

        $cleared = [];
        Schema::disableForeignKeyConstraints();
        try {
            foreach ($operations as $table => $delete) {
                if (Schema::hasTable($table)) {
                    $delete();
                    $cleared[] = $table;
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $cleared;
    }

    /** @return array<string, Closure(): void> */
    private function operationalDeletes(): array
    {
        return [
            'jobs' => fn () => DB::table('jobs')->delete(),
            'job_batches' => fn () => DB::table('job_batches')->delete(),
            'failed_jobs' => fn () => DB::table('failed_jobs')->delete(),
            'cache_locks' => fn () => DB::table('cache_locks')->delete(),
            'cache' => fn () => DB::table('cache')->delete(),
            'sessions' => fn () => DB::table('sessions')->delete(),
            'chat_messages' => fn () => DB::table('chat_messages')->delete(),
            'chat_sessions' => fn () => DB::table('chat_sessions')->delete(),
            'pipeline_items' => fn () => DB::table('pipeline_items')->delete(),
            'pipeline_events' => fn () => DB::table('pipeline_events')->delete(),
            'actor_executions' => fn () => DB::table('actor_executions')->delete(),
            'poll_candidates' => fn () => DB::table('poll_candidates')->delete(),
            'commands' => fn () => DB::table('commands')->delete(),
            'pipeline_runs' => fn () => DB::table('pipeline_runs')->delete(),
            'webhook_deliveries' => fn () => DB::table('webhook_deliveries')->delete(),
            'document_ocr_corrections' => fn () => DB::table('document_ocr_corrections')->delete(),
            'document_embeddings' => fn () => DB::table('document_embeddings')->delete(),
            'embedding_index_state' => fn () => DB::table('embedding_index_state')->delete(),
            'llm_calls' => fn () => DB::table('llm_calls')->delete(),
            'entity_approvals' => fn () => DB::table('entity_approvals')->delete(),
            'ocr_reviews' => fn () => DB::table('ocr_reviews')->delete(),
            'review_suggestions' => fn () => DB::table('review_suggestions')->delete(),
            'audit_logs' => fn () => DB::table('audit_logs')->delete(),
        ];
    }

    /** @return array<string, Closure(): void> */
    private function configurationDeletes(): array
    {
        return [
            'mcp_tokens' => fn () => DB::table('mcp_tokens')->delete(),
            'app_settings' => fn () => DB::table('app_settings')->delete(),
            'setup_states' => fn () => DB::table('setup_states')->delete(),
        ];
    }
}
