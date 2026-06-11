<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\RunPythonActorJob;
use App\Models\AppSetting;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class PaperlessEventWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_simple_webhook_alias_persists_delivery_and_starts_pipeline_run(): void
    {
        $this->markEmbeddingIndexComplete();

        $response = $this->postJson(route('webhook.paperless'), [
            'event' => 'document_created',
            'document' => ['modified' => '2026-05-08T12:00:00Z'],
            'object' => ['id' => 42],
        ])->assertOk()->assertJson([
            'status' => 'processed',
            'duplicate' => false,
            'document_id' => 42,
            'pipeline_outcome' => 'created',
            'blocked_reason' => null,
        ]);

        $delivery = WebhookDelivery::query()->firstOrFail();
        $run = PipelineRun::query()->firstOrFail();

        $response->assertJson([
            'webhook_delivery_id' => $delivery->id,
            'pipeline_run_id' => $run->id,
        ]);

        $this->assertSame('paperless', $delivery->source);
        $this->assertSame('document_created', $delivery->event_type);
        $this->assertSame(42, $delivery->paperless_document_id);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $delivery->status);
        $this->assertSame('process_document', $delivery->normalized_payload['webhook_action']);
        $this->assertSame('2026-05-08T12:00:00Z', $delivery->normalized_payload['paperless_modified']);

        $this->assertSame(PipelineRun::STATUS_QUEUED, $run->status);
        $this->assertSame('webhook', $run->trigger_source);
        $this->assertSame(42, $run->paperless_document_id);
        $this->assertSame($delivery->id, $run->webhook_delivery_id);

        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'webhook.received',
            'paperless_document_id' => 42,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'pipeline.start.pending',
            'paperless_document_id' => 42,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'pipeline.document_actor_queued',
            'paperless_document_id' => 42,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'webhook.process_delivery_handled',
            'paperless_document_id' => 42,
        ]);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $run->id);
    }

    public function test_event_webhook_endpoint_persists_delivery_and_starts_pipeline_run(): void
    {
        $this->markEmbeddingIndexComplete();

        $this->postJson(route('api.webhooks.paperless'), [
            'event' => 'document_created',
            'document_id' => 43,
            'modified' => '2026-05-08T13:00:00Z',
        ])->assertOk()->assertJson([
            'status' => 'processed',
            'duplicate' => false,
            'document_id' => 43,
            'pipeline_outcome' => 'created',
        ]);

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertDatabaseHas('pipeline_runs', [
            'webhook_delivery_id' => $delivery->id,
            'trigger_source' => 'webhook',
            'paperless_document_id' => 43,
            'status' => PipelineRun::STATUS_QUEUED,
        ]);
    }

    public function test_gate_closed_persists_delivery_and_creates_blocked_pipeline_run(): void
    {
        EmbeddingIndexState::query()->create(['status' => EmbeddingIndexState::STATUS_STALE]);

        $this->postJson(route('api.webhooks.paperless'), [
            'event' => 'document_created',
            'document_id' => 44,
        ])->assertOk()->assertJson([
            'status' => 'blocked',
            'duplicate' => false,
            'document_id' => 44,
            'pipeline_outcome' => 'blocked',
            'blocked_reason' => 'embedding_index_not_ready',
        ]);

        $delivery = WebhookDelivery::query()->firstOrFail();
        $run = PipelineRun::query()->firstOrFail();
        $this->assertSame(WebhookDelivery::STATUS_BLOCKED, $delivery->status);
        $this->assertSame('embedding_index_not_ready', $delivery->error);
        $this->assertSame($delivery->id, $run->webhook_delivery_id);
        $this->assertSame(PipelineRun::STATUS_BLOCKED, $run->status);
        $this->assertSame('embedding_index_not_ready', $run->error_type);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'pipeline.blocked.embedding_index_not_ready',
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'webhook.process_delivery_blocked',
        ]);
    }

    public function test_event_webhook_redacts_persisted_secrets_but_hashes_original_payload(): void
    {
        Queue::fake();

        $payload = [
            'event' => 'document_updated',
            'document_id' => 7,
            'api_key' => 'payload-secret',
            'nested' => [
                'token' => 'nested-token',
                'safe' => 'visible',
            ],
        ];
        $expectedHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        $this->withHeaders([
            'Authorization' => 'Bearer auth-secret',
            'X-Api-Key' => 'header-secret',
            'X-Safe-Header' => 'visible-header',
        ])->postJson(route('api.webhooks.paperless'), $payload)->assertOk();

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame($expectedHash, $delivery->payload_hash);
        $this->assertSame('[redacted]', $delivery->raw_payload['api_key']);
        $this->assertSame('[redacted]', $delivery->raw_payload['nested']['token']);
        $this->assertSame('visible', $delivery->raw_payload['nested']['safe']);
        $this->assertSame(['[redacted]'], $delivery->headers['authorization']);
        $this->assertSame(['[redacted]'], $delivery->headers['x-api-key']);
        $this->assertSame(['visible-header'], $delivery->headers['x-safe-header']);
        $this->assertStringNotContainsString('payload-secret', json_encode($delivery->raw_payload));
        $this->assertStringNotContainsString('nested-token', json_encode($delivery->raw_payload));
        $this->assertStringNotContainsString('header-secret', json_encode($delivery->headers));
    }

    public function test_event_webhook_deduplicates_identical_embedding_refresh_delivery_without_pipeline_start(): void
    {
        $this->markEmbeddingIndexComplete();
        $payload = [
            'event' => 'document_updated',
            'object' => ['id' => 7, 'modified' => '2026-05-08T13:00:00Z'],
        ];

        $this->postJson(route('api.webhooks.paperless'), $payload)->assertOk()->assertJson([
            'status' => 'queued',
            'duplicate' => false,
            'webhook_action' => 'refresh_embedding',
        ])->assertJsonMissing(['pipeline_outcome' => 'created']);
        $this->postJson(route('api.webhooks.paperless'), $payload)->assertOk()->assertJson([
            'status' => 'duplicate',
            'duplicate' => true,
            'webhook_action' => 'refresh_embedding',
        ])->assertJsonMissing(['pipeline_outcome' => 'created']);

        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseCount('pipeline_runs', 0);
        $this->assertDatabaseHas('pipeline_events', ['event_type' => 'webhook.duplicate']);
        $this->assertDatabaseHas('pipeline_events', ['event_type' => 'webhook.enqueue_requested']);
        $this->assertDatabaseCount('pipeline_events', 3);
    }

    public function test_updated_delivery_refreshes_embedding_without_coalescing_a_document_run(): void
    {
        $this->markEmbeddingIndexComplete();

        $this->postJson(route('api.webhooks.paperless'), [
            'event' => 'document_created',
            'object' => ['id' => 7, 'modified' => '2026-05-08T13:00:00Z'],
        ])->assertOk()->assertJson(['pipeline_outcome' => 'created']);

        $this->postJson(route('api.webhooks.paperless'), [
            'event' => 'document_updated',
            'object' => ['id' => 7, 'modified' => '2026-05-08T13:00:00Z'],
            'changed_field' => 'title',
        ])->assertOk()->assertJson(['webhook_action' => 'refresh_embedding'])
            ->assertJsonMissing(['pipeline_outcome' => 'coalesced']);

        $this->assertDatabaseCount('webhook_deliveries', 2);
        $this->assertDatabaseCount('pipeline_runs', 1);
        $this->assertDatabaseMissing('pipeline_events', ['event_type' => 'pipeline.start.coalesced']);
    }

    public function test_changed_modified_time_creates_second_pipeline_run_for_create_events(): void
    {
        $this->markEmbeddingIndexComplete();

        $this->postJson(route('api.webhooks.paperless'), [
            'event' => 'document_created',
            'object' => ['id' => 7, 'modified' => '2026-05-08T13:00:00Z'],
        ])->assertOk()->assertJson(['pipeline_outcome' => 'created']);

        $this->postJson(route('api.webhooks.paperless'), [
            'event' => 'document_created',
            'object' => ['id' => 7, 'modified' => '2026-05-08T14:00:00Z'],
        ])->assertOk()->assertJson(['pipeline_outcome' => 'created']);

        $this->assertDatabaseCount('webhook_deliveries', 2);
        $this->assertDatabaseCount('pipeline_runs', 2);
    }

    public function test_event_webhook_returns_retryable_failure_when_laravel_queue_dispatch_fails(): void
    {
        $this->markEmbeddingIndexComplete();
        Queue::shouldReceive('push')->andThrow(new RuntimeException('queue down secret-token'));

        $this->withHeader('X-Webhook-Secret', 'top-secret')
            ->postJson(route('api.webhooks.paperless'), [
                'event' => 'document_updated',
                'document_id' => 42,
                'changed_field' => 'title',
            ])
            ->assertStatus(503)
            ->assertJson([
                'status' => 'enqueue_failed',
                'retry' => true,
                'duplicate' => false,
                'document_id' => 42,
                'webhook_action' => 'refresh_embedding',
            ]);

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame(WebhookDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertDatabaseCount('pipeline_runs', 0);
        $event = PipelineEvent::query()
            ->where('event_type', 'webhook.enqueue_deferred')
            ->firstOrFail();

        $this->assertSame($delivery->id, $event->webhook_delivery_id);
        $this->assertSame('queue_dispatch_failed', $event->payload['error_type']);
        $this->assertSame(RuntimeException::class, $event->payload['exception_class']);
        $this->assertStringNotContainsString('top-secret', json_encode($event->payload));
        $this->assertStringNotContainsString('secret-token', json_encode($event->payload));
    }

    public function test_event_webhook_queues_embedding_refresh_through_laravel_actor_job(): void
    {
        $this->markEmbeddingIndexComplete();
        Config::set('archibot.python_binary', 'python-test');

        $this->postJson(route('api.webhooks.paperless'), [
            'event' => 'document_updated',
            'document_id' => 42,
            'changed_field' => 'title',
        ])->assertOk()->assertJson(['webhook_action' => 'refresh_embedding']);

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'webhook.enqueue_requested',
        ]);
        $this->assertSame(WebhookDelivery::STATUS_QUEUED, $delivery->fresh()->status);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === 'handle_paperless_webhook'
            && $job->commandId === $delivery->id);
    }

    public function test_event_webhook_duplicate_does_not_queue_second_webhook_actor(): void
    {
        $this->markEmbeddingIndexComplete();
        Config::set('archibot.python_binary', 'python-test');

        $payload = ['event' => 'document_updated', 'document_id' => 7];

        $this->postJson(route('api.webhooks.paperless'), $payload)->assertOk();
        $this->postJson(route('api.webhooks.paperless'), $payload)->assertOk()->assertJson([
            'status' => 'duplicate',
            'duplicate' => true,
        ]);

        Queue::assertPushed(RunPythonActorJob::class, 1);
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseCount('pipeline_runs', 0);
    }

    public function test_event_webhook_requires_secret_when_configured(): void
    {
        Config::set('archibot.paperless_webhook_secret', 'secret');

        $this->postJson(route('api.webhooks.paperless'), ['document_id' => 42])
            ->assertForbidden()
            ->assertJson(['detail' => 'Invalid webhook secret']);

        $this->assertDatabaseCount('webhook_deliveries', 0);
        $this->assertDatabaseCount('pipeline_runs', 0);

        $this->withHeader('X-Webhook-Secret', 'secret')
            ->postJson(route('api.webhooks.paperless'), ['document_id' => 42])
            ->assertOk();
    }

    public function test_event_webhook_requires_secret_from_app_settings(): void
    {
        AppSetting::put('webhook.secret', 'database-secret', encrypted: true);

        $this->withHeader('X-Webhook-Secret', 'env-secret')
            ->postJson(route('api.webhooks.paperless'), ['document_id' => 42])
            ->assertForbidden()
            ->assertJson(['detail' => 'Invalid webhook secret']);

        $this->assertDatabaseCount('webhook_deliveries', 0);

        $this->withHeader('X-Webhook-Secret', 'database-secret')
            ->postJson(route('api.webhooks.paperless'), ['document_id' => 42])
            ->assertOk();
    }

    public function test_event_webhook_rejects_missing_document_id(): void
    {
        $this->postJson(route('api.webhooks.paperless'), ['event' => 'document_created'])
            ->assertStatus(422)
            ->assertJson(['detail' => 'Could not extract document_id from payload']);

        $this->assertDatabaseCount('webhook_deliveries', 0);
        $this->assertDatabaseCount('pipeline_runs', 0);
    }

    private function markEmbeddingIndexComplete(): void
    {
        Queue::fake();
        EmbeddingIndexState::query()->create([
            'status' => EmbeddingIndexState::STATUS_COMPLETE,
            'embedding_model' => 'test-embed',
            'dimensions' => 1024,
            'content_scope' => 'trusted_documents',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
