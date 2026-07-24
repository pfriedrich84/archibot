<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Builds the narrow, typed data contract used by privileged diagnostics. */
class DiagnosticPresenter
{
    /** @var list<string> */
    private const INTEGER_KEYS = [
        'actor_user_id', 'attempt', 'command_id', 'document_id', 'duration_ms',
        'embedding_index_state_id', 'failed_count', 'failed_item_count',
        'max_attempts', 'max_retries', 'pipeline_run_id', 'paperless_document_id',
        'progress_done', 'progress_failed', 'progress_total', 'retry_count',
        'webhook_delivery_id',
    ];

    /** @var list<string> */
    private const BOOLEAN_KEYS = ['actor_is_admin', 'force'];

    /**
     * Canonical values inventoried from Laravel models/services and Python actors.
     * Unknown values are never echoed. Keep this inventory in sync when a durable
     * diagnostic type is added.
     *
     * @var array<string, list<string>>
     */
    private const ENUM_VALUES = [
        'action' => ['added', 'created', 'deleted', 'modified', 'updated'],
        'event' => [
            'added', 'created', 'deleted', 'modified', 'updated',
            'document.added', 'document.created', 'document.deleted', 'document.modified', 'document.updated',
        ],
        'event_type' => [
            'document_added', 'document_created', 'document_deleted', 'document_modified', 'document_updated',
            'document.added', 'document.created', 'document.deleted', 'document.modified', 'document.updated',
        ],
        'command_type' => [
            'embedding_index_build', 'poll_reconciliation', 'reindex', 'reindex_ocr',
            'review_commit', 'sync_entity_approval',
        ],
        'entity_type' => ['correspondent', 'document_type', 'tag'],
        'item_type' => ['classification', 'context_search', 'embedding', 'judge', 'ocr', 'paperless_fetch', 'review_suggestion'],
        'judge_verdict' => ['agree', 'corrected', 'error', 'skipped'],
        'level' => ['debug', 'info', 'notice', 'warning', 'error', 'critical'],
        'llm_provider' => ['ollama', 'openai_compatible'],
        'ocr_mode' => ['off', 'text', 'vision_light', 'vision_full'],
        'phase' => [
            'added', 'blocked', 'classification', 'classify', 'classify_publish', 'created', 'deleted', 'document_actor',
            'embed', 'embedding', 'embedding_index', 'embedding_index_prepare', 'failed',
            'delete_embedding', 'fetch', 'finalize', 'finished', 'idle', 'judge', 'modified', 'ocr', 'ocr_reindex_finished',
            'ocr_reindex_prepare', 'paperless_fetch', 'poll_reconciliation',
            'poll_reconciliation_prepare', 'postprocess', 'prepare', 'process_document', 'queued', 'refresh_embedding',
            'retry_failed_items', 'review', 'review_commit_finished', 'review_commit_load',
            'review_commit_paperless', 'review_suggestion', 'skipped', 'store',
            'updated', 'webhook_finished', 'webhook_normalize',
        ],
        'pipeline_type' => ['document', 'embedding_index', 'ocr_reindex', 'reconciliation', 'reindex'],
        'reprocess_mode' => ['automatic', 'manual', 'webhook'],
        'pipeline_outcome' => ['blocked', 'cancelled', 'failed', 'partial', 'succeeded'],
        'queue_name' => ['default', 'embeddings', 'laravel.database', 'pipeline'],
        'retry_class' => [
            'blocked_document_lock', 'blocked_embedding_index', 'bug_unexpected', 'cancelled',
            'permanent_missing_document', 'permanent_validation', 'rate_limited', 'recoverable_processing',
            'transient_network', 'transient_paperless', 'transient_provider',
        ],
        'retry_mode' => ['automatic', 'manual'],
        'webhook_action' => ['delete_embedding', 'process_document', 'refresh_embedding'],

        'source' => ['command', 'manual', 'paperless', 'poll', 'reconciliation', 'webhook'],
        'status' => [
            'accepted', 'approved', 'blocked', 'building', 'cancel_requested', 'cancelled',
            'committed', 'complete', 'completed', 'dismissed', 'duplicate', 'error', 'failed',
            'failed_permanent', 'missing', 'partial', 'partially_failed', 'pending', 'processed', 'queued',
            'received', 'rejected', 'retrying', 'running', 'skipped', 'stale', 'succeeded', 'synced',
        ],
        'trigger_source' => ['command', 'manual', 'poll', 'reconciliation', 'webhook'],
    ];

    /** @var list<string> */
    private const ACTOR_NAMES = [
        'build_embedding_index', 'build_initial_embedding_index', 'commit_review_suggestion',
        'handle_document_pipeline', 'handle_paperless_webhook', 'reconcile_inbox_documents',
        'reindex', 'reindex_ocr', 'sync_entity_approval',
    ];

    /**
     * Canonical error types/classes emitted by our Laravel and Python code.
     * Never accept arbitrary exception-shaped strings: credentials can be made
     * to look like class names (for example, AuthorizationTokenSecretError).
     *
     * @var list<string>
     */
    private const ERROR_TYPES = [
        'RuntimeError', 'ValueError', 'TimeoutError', 'ConnectionError',
        'RuntimeException', 'InvalidArgumentException', 'LogicException', 'PDOException',
        'App\\Services\\Paperless\\PaperlessUnavailableException',
        'Illuminate\\Auth\\Access\\AuthorizationException',
        'Illuminate\\Database\\QueryException',
        'Illuminate\\Http\\Client\\ConnectionException',
        'Illuminate\\Http\\Client\\RequestException',
        'Illuminate\\Queue\\MaxAttemptsExceededException',
        'Symfony\\Component\\Process\\Exception\\ProcessFailedException',
        'Symfony\\Component\\Process\\Exception\\ProcessTimedOutException',
        'actor_process_failed', 'actor_retry_redispatched', 'blocked_document_lock',
        'blocked_embedding_index', 'bug_unexpected', 'cancel_requested', 'cancelled',
        'embedding_documents_failed', 'embedding_index_already_building',
        'embedding_index_not_ready', 'enqueue_failed', 'inbox_tag_not_configured',
        'invalid_webhook_action', 'missing_document_id', 'missing_entity_sync_correspondent_id',
        'missing_entity_sync_document_type_id', 'missing_entity_sync_tag_id',
        'missing_review_suggestion_id', 'ocr_mode_off', 'permanent_missing_document',
        'permanent_validation', 'pipeline_run_not_found', 'pipeline_start_failed',
        'poll_reconciliation_enqueue_failed', 'queue_dispatch_failed', 'rate_limited',
        'recoverable_processing', 'review_suggestion_not_found',
        'source_link_unavailable_after_upgrade', 'superseded_by_newer_attempt',
        'transient_network', 'transient_paperless', 'transient_provider',
        'worker_recovery_stale_actor',
    ];

    /** @var list<string> */
    private const DIAGNOSTIC_EVENT_TYPES = [
        'actor.failed', 'actor.recovered_stale', 'actor.retry_scheduled', 'actor.started', 'actor.succeeded',
        'admin_prompt.reset', 'admin_prompt.updated', 'admin_settings.updated', 'auth.login',
        'document.actor.ready', 'document.auto_commit.requested', 'document.auto_commit.skipped',
        'document.classified', 'document.context.searched', 'document.embedding.deleted',
        'document.embedding.refreshed', 'document.embedding.refresh_skipped', 'document.fetched',
        'document.judge.completed', 'document.ocr.corrected', 'document.ocr.skipped',
        'document.processing.blocked_not_migrated', 'document.review_suggestion.stored',
        'embedding_index.build.completed', 'embedding_index.build.failed', 'embedding_index.build.started',
        'embedding_index.build_requested', 'embedding_index.marked_stale',
        'entity_approval.approved', 'entity_approval.rejected', 'entity_approval.unblacklisted',
        'job_control.embedding_build_actor_queued', 'job_control.embedding_build_requested',
        'job_control.entity_approval_sync_requested', 'job_control.ocr_reindex_actor_queued',
        'job_control.ocr_reindex_requested', 'job_control.poll_reconciliation_actor_queued',
        'job_control.poll_reconciliation_requested', 'job_control.reindex_actor_queued',
        'job_control.reindex_requested', 'job_control.retry_failed_items_requested',
        'job_control.review_commit_actor_queued', 'job_control.review_commit_requested',
        'job_control.webhook_failure_dismissed', 'job_control.webhook_retry_requested',
        'maintenance.ocr_reindex_requested', 'maintenance.pipeline_recovery_requested',
        'maintenance.poll_reconciliation_requested', 'maintenance.reindex_requested', 'maintenance.reset_requested',
        'mcp_token.created', 'mcp_token.revoked', 'ocr_review.approved', 'ocr_review.created', 'ocr_review.rejected',
        'paperless.delivery.failed',
        'pipeline.blocked.embedding_index_not_ready', 'pipeline.cancelled',
        'pipeline.document_actor_queued', 'pipeline.failed', 'pipeline.running', 'pipeline.succeeded',
        'pipeline.force_reprocess.requested', 'pipeline.start.attached', 'pipeline.start.coalesced',
        'pipeline.start.pending', 'pipeline.unblocked.embedding_index_ready',
        'ocr.reindex.completed', 'ocr.reindex.skipped',
        'poll.document.skipped_already_classified', 'poll.reconciliation.completed', 'poll.reconciliation.skipped',
        'pipeline_run.cancel_requested', 'pipeline_run.manual_reprocess_queued',
        'pipeline_run.retry_failed_items_queued', 'pipeline_run.retry_queued',
        'recovery.actor_execution_claim_lost', 'recovery.actor_execution_failed_permanent',
        'recovery.actor_execution_marked_retrying', 'recovery.actor_execution_reconciled_terminal_source',
        'recovery.actor_execution_redispatched', 'recovery.actor_execution_superseded',
        'recovery.actor_source_command_redispatched', 'recovery.actor_source_pipeline_redispatched',
        'recovery.actor_source_webhook_redispatched', 'recovery.command_actor_redispatched',
        'recovery.command_failed_permanent', 'recovery.document_actor_redispatched',
        'recovery.embedding_gate_released', 'recovery.failed_webhook_actor_redispatched',
        'recovery.process_webhook_reconciled', 'recovery.process_webhook_reconciliation_failed',
        'recovery.stale_queued_command_actor_redispatched', 'recovery.stale_queued_document_actor_redispatched',
        'recovery.stale_running_command_actor_redispatched', 'recovery.stale_running_document_actor_redispatched',
        'recovery.webhook_actor_redispatched', 'recovery.webhook_embedding_gate_released',
        'review.commit.skipped', 'review.commit.succeeded',
        'review_suggestion.accepted', 'review_suggestion.rejected', 'review_suggestion.saved',
        'scheduler.poll_reconciliation_actor_queued', 'scheduler.poll_reconciliation_enqueue_failed',
        'scheduler.poll_reconciliation_requested', 'setup.completed', 'setup.reset',
        'webhook.duplicate', 'webhook.empty_payload_poll_enqueue_failed',
        'webhook.empty_payload_poll_queued', 'webhook.empty_payload_poll_requested',
        'webhook.enqueue_deferred', 'webhook.enqueue_requested', 'webhook.invalid_duplicate',
        'webhook.invalid_payload', 'webhook.pipeline_start_failed', 'webhook.process_delivery_blocked',
        'webhook.process_delivery_handled', 'webhook.received',
        'webhook.invalid_action', 'webhook.normalized',
        'webhook_delivery.failure_dismissed', 'webhook_delivery.retry_queued',
    ];

    /** @param array<string, mixed>|null $metadata */
    public function metadata(?array $metadata): array
    {
        return $this->entries($metadata, array_merge(
            self::INTEGER_KEYS,
            self::BOOLEAN_KEYS,
            ['actor_name', 'error_type'],
            array_keys(self::ENUM_VALUES),
        ));
    }

    /** @param array<string, mixed>|null $payload */
    public function webhook(?array $payload): array
    {
        return $this->entries($payload, ['action', 'document_id', 'event', 'event_type', 'webhook_action']);
    }

    public function redactedMessage(mixed $message): ?string
    {
        return ! is_string($message) || trim($message) === ''
            ? null
            : 'Details redacted. Use the status, error type, identifiers and timeline to diagnose or recover this operation.';
    }

    public function webhookEventType(mixed $value): string
    {
        return $this->allowlistedOrSummary('event type', $value, self::ENUM_VALUES['event_type']);
    }

    public function diagnosticEventType(mixed $value): string
    {
        return $this->allowlistedOrSummary('event type', $value, self::DIAGNOSTIC_EVENT_TYPES);
    }

    public function typedScalar(string $key, mixed $value): string
    {
        if ($key === 'queue_name') {
            return $this->queueName($value);
        }

        return $this->allowlistedOrSummary($key, $value, self::ENUM_VALUES[$key] ?? []);
    }

    /** Normalize configurable legacy queue prefixes while preserving the operational lane. */
    public function queueName(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::ENUM_VALUES['queue_name'], true)) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,47}\.(io|blocking|embedding|webhook)\z/', $value, $matches) === 1) {
            return 'legacy.'.$matches[1];
        }

        return $this->allowlistedOrSummary('queue name', $value, []);
    }

    public function actorName(mixed $value): string
    {
        return $this->allowlistedOrSummary('actor', $value, self::ACTOR_NAMES);
    }

    public function errorType(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($this->isSafeErrorType($value)) {
            return $value;
        }

        return $this->allowlistedOrSummary('error type', $value, []);
    }

    /**
     * Model IDs are configurable external values and therefore never safe to
     * echo. A stable reference preserves equality/comparison utility without
     * making the configured value reversible in browser diagnostics.
     */
    public function modelIdentifier(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->scalarSummary('configured model', $value) ?? 'Configured model unavailable';
    }

    /**
     * Only provider identifiers defined by the application's provider-type
     * contract may be displayed canonically. Configurable profile IDs and all
     * unknown values are opaque references, regardless of identifier grammar.
     */
    public function providerIdentifier(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->allowlistedOrSummary('configured provider', $value, [
            'default',
            ...self::ENUM_VALUES['llm_provider'],
        ]);
    }

    /** Sanitize every field in the browser-bound embedding snapshot. */
    public function embeddingSnapshot(array $snapshot): array
    {
        return [
            'id' => $this->nonNegativeIntegerOrNull($snapshot['id'] ?? null),
            'status' => $this->typedScalar('status', $snapshot['status'] ?? null),
            'embedding_model' => $this->modelIdentifier($snapshot['embedding_model'] ?? null),
            'dimensions' => $this->nonNegativeIntegerOrNull($snapshot['dimensions'] ?? null),
            'document_count' => $this->nonNegativeInteger($snapshot['document_count'] ?? null),
            'document_count_known' => is_bool($snapshot['document_count_known'] ?? null) ? $snapshot['document_count_known'] : false,
            'embedded_count' => $this->nonNegativeInteger($snapshot['embedded_count'] ?? null),
            'stored_embedding_rows' => $this->nonNegativeInteger($snapshot['stored_embedding_rows'] ?? null),
            'pgvector_embedded_count' => $this->nonNegativeInteger($snapshot['pgvector_embedded_count'] ?? null),
            'missing_count' => $this->nonNegativeIntegerOrNull($snapshot['missing_count'] ?? null),
            'failed_count' => $this->nonNegativeInteger($snapshot['failed_count'] ?? null),
            'started_at' => $this->timestamp($snapshot['started_at'] ?? null),
            'completed_at' => $this->timestamp($snapshot['completed_at'] ?? null),
            'error' => $this->redactedMessage(is_string($snapshot['error'] ?? null) ? $snapshot['error'] : null),
            'document_count_error' => $this->redactedMessage(is_string($snapshot['document_count_error'] ?? null) ? $snapshot['document_count_error'] : null),
            'ready' => is_bool($snapshot['ready'] ?? null) ? $snapshot['ready'] : false,
            'scope' => is_string($snapshot['scope'] ?? null) && $snapshot['scope'] !== '' ? $snapshot['scope'] : null,
            'release_threshold' => $this->nonNegativeInteger($snapshot['release_threshold'] ?? null),
            'release_target_population' => $this->nonNegativeInteger($snapshot['release_target_population'] ?? null),
            'release_status' => $this->typedScalar('status', $snapshot['release_status'] ?? null),
            'released_at' => $this->timestamp($snapshot['released_at'] ?? null),
            'released' => is_bool($snapshot['released'] ?? null) ? $snapshot['released'] : false,
        ];
    }

    /** Aggregate dynamic count keys into canonical values plus a non-leaking unknown bucket. */
    public function typedCounts(array $counts, string $key): array
    {
        $safe = [];
        foreach ($counts as $rawKey => $count) {
            $canonical = $this->canonicalValue($key, $rawKey) ?? 'unknown';
            $safe[$canonical] = ($safe[$canonical] ?? 0) + $this->nonNegativeInteger($count);
        }
        ksort($safe);

        return $safe;
    }

    public function actorCounts(array $counts): array
    {
        return $this->allowlistedCounts($counts, self::ACTOR_NAMES);
    }

    public function typedMatrix(array $matrix, string $rowKey, string $columnKey): array
    {
        $safe = [];
        foreach ($matrix as $rawRow => $columns) {
            $row = $rowKey === 'actor_name'
                ? (in_array($rawRow, self::ACTOR_NAMES, true) ? $rawRow : 'unknown')
                : ($this->canonicalValue($rowKey, $rawRow) ?? 'unknown');
            foreach (is_array($columns) ? $columns : [] as $rawColumn => $count) {
                $column = $this->canonicalValue($columnKey, $rawColumn) ?? 'unknown';
                $safe[$row][$column] = ($safe[$row][$column] ?? 0) + $this->nonNegativeInteger($count);
            }
        }
        ksort($safe);
        foreach ($safe as &$columns) {
            ksort($columns);
        }

        return $safe;
    }

    public function opaqueReference(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return 'ref:'.substr(hash('sha256', $value), 0, 12);
    }

    public function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 64) {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : gmdate('c', $timestamp);
    }

    public function scalarSummary(string $label, mixed $value): ?string
    {
        $reference = $this->opaqueReference($value);

        return $reference === null ? null : Str::of($label)->replace('_', ' ')->title()->toString().' ('.$reference.')';
    }

    /** @param list<string> $allowed */
    private function allowlistedOrSummary(string $label, mixed $value, array $allowed): string
    {
        if (is_string($value) && in_array($value, $allowed, true)) {
            return $value;
        }

        return $this->scalarSummary($label, $value) ?? 'Unknown '.Str::of($label)->replace('_', ' ')->toString();
    }

    private function isSafeErrorType(mixed $value): bool
    {
        return is_string($value) && in_array($value, self::ERROR_TYPES, true);
    }

    private function canonicalValue(string $key, mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::ENUM_VALUES[$key] ?? [], true) ? $value : null;
    }

    /** @param list<string> $allowed */
    private function allowlistedCounts(array $counts, array $allowed): array
    {
        $safe = [];
        foreach ($counts as $key => $count) {
            $key = is_string($key) && in_array($key, $allowed, true) ? $key : 'unknown';
            $safe[$key] = ($safe[$key] ?? 0) + $this->nonNegativeInteger($count);
        }
        ksort($safe);

        return $safe;
    }

    private function nonNegativeInteger(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }

    private function nonNegativeIntegerOrNull(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /** @param list<string> $allowedKeys */
    private function entries(?array $values, array $allowedKeys): array
    {
        $entries = [];
        foreach ($values ?? [] as $key => $value) {
            $key = (string) $key;
            if (! in_array($key, $allowedKeys, true)) {
                continue;
            }
            $safeValue = $this->safeValue($key, $value);
            if ($safeValue !== null) {
                $entries[] = [
                    'key' => $key,
                    'label' => Str::of($key)->replace('_', ' ')->title()->toString(),
                    'value' => $safeValue,
                ];
            }
        }

        return $entries;
    }

    private function safeValue(string $key, mixed $value): bool|int|string|null
    {
        if (in_array($key, self::INTEGER_KEYS, true)) {
            return is_int($value) && $value >= 0 ? $value : null;
        }
        if (in_array($key, self::BOOLEAN_KEYS, true)) {
            return is_bool($value) ? $value : null;
        }
        if ($key === 'actor_name') {
            return is_string($value) && in_array($value, self::ACTOR_NAMES, true) ? $value : null;
        }
        if ($key === 'error_type') {
            return $this->errorType($value);
        }
        if ($key === 'queue_name') {
            $queue = $this->queueName($value);

            return str_starts_with($queue, 'Queue Name (ref:') || $queue === 'Unknown queue name' ? null : $queue;
        }

        return $this->canonicalValue($key, $value);
    }
}
