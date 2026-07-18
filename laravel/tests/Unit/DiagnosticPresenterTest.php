<?php

namespace Tests\Unit;

use App\Support\DiagnosticPresenter;
use PHPUnit\Framework\TestCase;

class DiagnosticPresenterTest extends TestCase
{
    public function test_metadata_contract_allows_only_labeled_diagnostic_scalars(): void
    {
        $entries = (new DiagnosticPresenter)->metadata([
            'pipeline_run_id' => 17,
            'status' => 'retrying',
            'authorization' => 'Bearer test-secret',
            'token' => 'test-secret',
            'prompt' => 'test prompt content',
            'ocr_content' => 'test OCR content',
            'document_content' => 'test document content',
            'nested' => ['document_id' => 42],
        ]);

        $this->assertSame([
            ['key' => 'pipeline_run_id', 'label' => 'Pipeline Run Id', 'value' => 17],
            ['key' => 'status', 'label' => 'Status', 'value' => 'retrying'],
        ], $entries);
    }

    public function test_allowlisted_keys_still_reject_wrong_types_and_adversarial_strings(): void
    {
        $entries = (new DiagnosticPresenter)->metadata([
            'pipeline_run_id' => '17',
            'actor_is_admin' => 'true',
            'status' => 'Bearer test-secret',
            'phase' => 'private OCR content',
            'event_type' => 'prompt: reveal document content',
            'retry_mode' => ['manual'],
            'duration_ms' => -1,
            'force' => true,
            'level' => 'error',
        ]);

        $this->assertSame([
            ['key' => 'force', 'label' => 'Force', 'value' => true],
            ['key' => 'level', 'label' => 'Level', 'value' => 'error'],
        ], $entries);
    }

    public function test_webhook_contract_does_not_return_headers_or_unknown_payload_fields(): void
    {
        $entries = (new DiagnosticPresenter)->webhook([
            'document_id' => 42,
            'event' => 'document.updated',
            'webhook_action' => 'refresh_embedding',
            'title' => 'test private title',
            'content' => 'test private document content',
            'headers' => ['authorization' => 'Bearer test-secret'],
        ]);

        $this->assertSame([
            ['key' => 'document_id', 'label' => 'Document Id', 'value' => 42],
            ['key' => 'event', 'label' => 'Event', 'value' => 'document.updated'],
            ['key' => 'webhook_action', 'label' => 'Webhook Action', 'value' => 'refresh_embedding'],
        ], $entries);
    }

    public function test_webhook_allowlisted_string_keys_accept_only_fixed_enum_values(): void
    {
        $entries = (new DiagnosticPresenter)->webhook([
            'document_id' => 42,
            'event' => 'Bearer test-secret',
            'event_type' => 'private document title',
            'action' => 'OCR content',
            'webhook_action' => 'updated',
        ]);

        $this->assertSame([
            ['key' => 'document_id', 'label' => 'Document Id', 'value' => 42],
        ], $entries);
    }

    public function test_top_level_diagnostic_scalars_use_typed_values_or_stable_non_reversible_summaries(): void
    {
        $presenter = new DiagnosticPresenter;
        $malicious = 'document.updated<script>private modified value Bearer secret';

        $this->assertSame('document.updated', $presenter->webhookEventType('document.updated'));
        $this->assertSame(
            'Event Type (ref:'.substr(hash('sha256', $malicious), 0, 12).')',
            $presenter->webhookEventType($malicious),
        );
        $this->assertSame('ref:'.substr(hash('sha256', $malicious), 0, 12), $presenter->opaqueReference($malicious));
        $this->assertSame(
            'Event Type (ref:'.substr(hash('sha256', $malicious), 0, 12).')',
            $presenter->diagnosticEventType($malicious),
        );
        $this->assertStringNotContainsString('private', $presenter->webhookEventType($malicious));
        $this->assertStringNotContainsString('secret', $presenter->opaqueReference($malicious));
    }

    public function test_canonical_recovery_diagnostics_survive_but_arbitrary_identifiers_do_not(): void
    {
        $presenter = new DiagnosticPresenter;

        $this->assertSame('build_initial_embedding_index', $presenter->actorName('build_initial_embedding_index'));
        $this->assertSame('review_commit_paperless', $presenter->typedScalar('phase', 'review_commit_paperless'));
        $this->assertSame('pipeline_run.retry_queued', $presenter->diagnosticEventType('pipeline_run.retry_queued'));
        $this->assertSame('poll.reconciliation.completed', $presenter->diagnosticEventType('poll.reconciliation.completed'));
        $this->assertSame('ocr.reindex.skipped', $presenter->diagnosticEventType('ocr.reindex.skipped'));
        $this->assertSame('scheduler.poll_reconciliation_enqueue_failed', $presenter->diagnosticEventType('scheduler.poll_reconciliation_enqueue_failed'));
        $this->assertSame('transient_network', $presenter->typedScalar('retry_class', 'transient_network'));
        $this->assertSame('laravel.database', $presenter->queueName('laravel.database'));
        $this->assertSame('legacy.io', $presenter->queueName('custom-prefix.io'));
        $this->assertSame('transient_network', $presenter->errorType('transient_network'));
        $this->assertSame('RuntimeError', $presenter->errorType('RuntimeError'));
        $this->assertSame('Illuminate\\Http\\Client\\ConnectionException', $presenter->errorType('Illuminate\\Http\\Client\\ConnectionException'));
        $this->assertSame('App\\Services\\Paperless\\PaperlessUnavailableException', $presenter->errorType('App\\Services\\Paperless\\PaperlessUnavailableException'));
        $this->assertStringNotContainsString('SECRET', $presenter->actorName('handle_document_pipeline.SECRET'));
        $this->assertStringNotContainsString('SECRET', (string) $presenter->errorType('RuntimeError.SECRET'));
        $errorReference = 'Error Type (ref:'.substr(hash('sha256', 'AuthorizationTokenSecretError'), 0, 12).')';
        $this->assertSame($errorReference, $presenter->errorType('AuthorizationTokenSecretError'));
        $this->assertSame([
            ['key' => 'error_type', 'label' => 'Error Type', 'value' => $errorReference],
        ], $presenter->metadata(['error_type' => 'AuthorizationTokenSecretError']));
    }

    public function test_configurable_provider_and_model_identifiers_never_echo_grammar_conforming_secrets(): void
    {
        $presenter = new DiagnosticPresenter;

        $this->assertSame('ollama', $presenter->providerIdentifier('ollama'));
        $this->assertSame('openai_compatible', $presenter->providerIdentifier('openai_compatible'));
        $this->assertSame('default', $presenter->providerIdentifier('default'));
        $this->assertSame(
            'Configured Provider (ref:'.substr(hash('sha256', 'token_secret_123'), 0, 12).')',
            $presenter->providerIdentifier('token_secret_123'),
        );
        $this->assertSame(
            'Configured Model (ref:'.substr(hash('sha256', 'sk-prod-secret123'), 0, 12).')',
            $presenter->modelIdentifier('sk-prod-secret123'),
        );
        $this->assertStringNotContainsString('token_secret_123', (string) $presenter->providerIdentifier('token_secret_123'));
        $this->assertStringNotContainsString('sk-prod-secret123', (string) $presenter->modelIdentifier('sk-prod-secret123'));
        // The only remaining grammar-based normalization returns a fixed lane,
        // never the configurable queue prefix that matched the grammar.
        $this->assertSame('legacy.io', $presenter->queueName('token_secret_123.io'));
        $this->assertStringNotContainsString('token_secret_123', $presenter->queueName('token_secret_123.io'));
    }

    public function test_stats_boundary_types_dynamic_keys_and_legacy_phase_health(): void
    {
        $presenter = new DiagnosticPresenter;

        $this->assertSame(
            ['failed' => 2, 'unknown' => 4],
            $presenter->typedCounts(['failed' => 2, 'Bearer STATS_SECRET' => 4], 'status'),
        );
        $this->assertSame(
            [
                'handle_document_pipeline' => ['running' => 3],
                'unknown' => ['unknown' => 5],
            ],
            $presenter->typedMatrix([
                'handle_document_pipeline' => ['running' => 3],
                'ACTOR_SECRET' => ['STATUS_SECRET' => 5],
            ], 'actor_name', 'status'),
        );
    }

    public function test_embedding_snapshot_types_every_field_and_references_model_ids(): void
    {
        $presenter = new DiagnosticPresenter;
        $snapshot = $presenter->embeddingSnapshot([
            'id' => 9,
            'status' => 'building',
            'embedding_model' => 'nomic-embed-text:v1.5',
            'dimensions' => 768,
            'document_count' => 10,
            'document_count_known' => true,
            'embedded_count' => 4,
            'stored_embedding_rows' => 4,
            'pgvector_embedded_count' => 4,
            'missing_count' => 6,
            'failed_count' => 0,
            'started_at' => '2026-07-17T10:00:00+00:00',
            'completed_at' => null,
            'error' => null,
            'document_count_error' => null,
            'ready' => false,
            'extra' => 'SNAPSHOT_SECRET',
        ]);

        $this->assertSame('building', $snapshot['status']);
        $this->assertSame(
            'Configured Model (ref:'.substr(hash('sha256', 'nomic-embed-text:v1.5'), 0, 12).')',
            $snapshot['embedding_model'],
        );
        $this->assertSame(768, $snapshot['dimensions']);
        $this->assertArrayNotHasKey('extra', $snapshot);

        $malicious = $presenter->embeddingSnapshot([
            'status' => 'building STATUS_SECRET',
            'embedding_model' => 'Bearer MODEL SECRET<script>',
            'dimensions' => '768 DIMENSION_SECRET',
            'document_count' => 'COUNT_SECRET',
            'document_count_known' => 'true SECRET',
            'ready' => 'true SECRET',
            'error' => 'ERROR_SECRET',
        ]);
        $encoded = json_encode($malicious, JSON_THROW_ON_ERROR);
        foreach (['STATUS_SECRET', 'MODEL SECRET', 'DIMENSION_SECRET', 'COUNT_SECRET', 'ERROR_SECRET'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_free_form_messages_are_replaced_with_a_fixed_redaction_notice(): void
    {
        $presenter = new DiagnosticPresenter;

        $this->assertNull($presenter->redactedMessage(null));
        $this->assertNull($presenter->redactedMessage('  '));
        $this->assertSame(
            'Details redacted. Use the status, error type, identifiers and timeline to diagnose or recover this operation.',
            $presenter->redactedMessage('Bearer test-secret and test private content'),
        );
    }
}
