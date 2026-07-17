<?php

namespace Tests\Feature\Webhooks;

use App\Http\Middleware\ValidatePaperlessWebhookRequest;
use App\Jobs\RunPythonActorJob;
use App\Models\AppSetting;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use App\Services\Pipeline\DocumentPipelineStarter;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

class PaperlessEventWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'synthetic-webhook-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('archibot.paperless_webhook_secret', self::WEBHOOK_SECRET);
        Config::set('archibot.paperless_webhook_rate_limit_per_minute', 60);
        $this->withHeader('X-Webhook-Secret', self::WEBHOOK_SECRET);
        RateLimiter::clear(ValidatePaperlessWebhookRequest::rateLimitKey('127.0.0.1'));
    }

    public function test_webhook_security_runs_once_globally_without_route_throttle(): void
    {
        $globalMiddleware = app(HttpKernel::class)->getMiddleware();

        $this->assertSame(1, count(array_filter(
            $globalMiddleware,
            fn (string $middleware): bool => $middleware === ValidatePaperlessWebhookRequest::class,
        )));

        foreach (['webhook.paperless', 'api.webhooks.paperless'] as $routeName) {
            $routeMiddleware = app('router')->getRoutes()->getByName($routeName)->middleware();

            $this->assertNotContains('throttle:paperless-webhook', $routeMiddleware);
            $this->assertNotContains('paperless.webhook', $routeMiddleware);
            $this->assertNotContains(ValidatePaperlessWebhookRequest::class, $routeMiddleware);
        }
    }

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

    public function test_empty_paperless_webhook_payload_queues_poll_reconciliation_hint(): void
    {
        Queue::fake();

        $response = $this->postJson(route('api.webhooks.paperless'), [])
            ->assertOk()
            ->assertJson([
                'status' => WebhookDelivery::STATUS_PROCESSED,
                'duplicate' => false,
                'webhook_action' => 'poll_reconciliation',
                'detail' => 'Empty Paperless webhook payload accepted as poll reconciliation hint.',
            ]);

        $delivery = WebhookDelivery::query()->firstOrFail();
        $command = Command::query()->firstOrFail();
        $response->assertJson([
            'webhook_delivery_id' => $delivery->id,
            'command_id' => $command->id,
        ]);
        $this->assertNull($delivery->paperless_document_id);
        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $delivery->status);
        $this->assertSame('poll_reconciliation', $delivery->normalized_payload['webhook_action']);
        $this->assertSame(Command::TYPE_POLL_RECONCILIATION, $command->type);
        $this->assertSame(Command::STATUS_QUEUED, $command->status);
        $this->assertNull($command->created_by_user_id);
        $this->assertSame('webhook_empty_payload', $command->payload['trigger_source']);
        $this->assertSame($delivery->id, $command->payload['webhook_delivery_id']);
        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $delivery->id,
            'command_id' => $command->id,
            'event_type' => 'webhook.empty_payload_poll_queued',
        ]);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === 'reconcile_inbox_documents'
            && $job->commandId === $command->id);
    }

    public function test_empty_paperless_webhook_payload_is_deduplicated_without_second_poll(): void
    {
        Queue::fake();

        $this->postJson(route('api.webhooks.paperless'), [])->assertOk();
        $this->postJson(route('api.webhooks.paperless'), [])->assertOk()->assertJson([
            'status' => WebhookDelivery::STATUS_DUPLICATE,
            'duplicate' => true,
            'webhook_action' => 'poll_reconciliation',
        ]);

        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseCount('commands', 1);
        Queue::assertPushed(RunPythonActorJob::class, 1);
    }

    public function test_event_webhook_accepts_paperless_scalar_document_payload(): void
    {
        $this->markEmbeddingIndexComplete();

        $this->postJson(route('api.webhooks.paperless'), [
            'event' => 'document_created',
            'document' => 45,
        ])->assertOk()->assertJson([
            'status' => 'processed',
            'duplicate' => false,
            'document_id' => 45,
            'pipeline_outcome' => 'created',
        ]);

        $this->assertDatabaseHas('webhook_deliveries', [
            'paperless_document_id' => 45,
            'status' => WebhookDelivery::STATUS_PROCESSED,
        ]);
        $this->assertDatabaseHas('pipeline_runs', [
            'trigger_source' => 'webhook',
            'paperless_document_id' => 45,
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
        $this->assertSame(['[redacted]'], $delivery->headers['x-webhook-secret']);
        $this->assertSame(['visible-header'], $delivery->headers['x-safe-header']);
        $this->assertStringNotContainsString('payload-secret', json_encode($delivery->raw_payload));
        $this->assertStringNotContainsString('nested-token', json_encode($delivery->raw_payload));
        $this->assertStringNotContainsString('header-secret', json_encode($delivery->headers));
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, json_encode($delivery->headers));
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
        Config::set('archibot.paperless_webhook_secret', 'top-secret');
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
        $this->assertSame(WebhookDelivery::STATUS_RECEIVED, $delivery->status);
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

    public function test_duplicate_non_process_delivery_retries_initial_enqueue_failure(): void
    {
        $this->markEmbeddingIndexComplete();
        $pushes = 0;
        Queue::shouldReceive('push')->twice()->andReturnUsing(function () use (&$pushes): void {
            $pushes++;
            if ($pushes === 1) {
                throw new RuntimeException('queue temporarily unavailable');
            }
        });
        $payload = [
            'event' => 'document_updated',
            'document_id' => 47,
            'modified' => '2026-05-08T15:01:00Z',
        ];

        $this->postJson(route('api.webhooks.paperless'), $payload)
            ->assertStatus(503)
            ->assertJson(['status' => 'enqueue_failed', 'duplicate' => false]);
        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame(WebhookDelivery::STATUS_RECEIVED, $delivery->status);

        $this->postJson(route('api.webhooks.paperless'), $payload)
            ->assertOk()
            ->assertJson(['status' => 'duplicate', 'duplicate' => true]);

        $this->assertSame(WebhookDelivery::STATUS_QUEUED, $delivery->fresh()->status);
        $this->assertSame(1, PipelineEvent::query()->where('event_type', 'webhook.enqueue_requested')->count());
    }

    public function test_duplicate_process_delivery_retries_pipeline_start_after_initial_failure(): void
    {
        $this->markEmbeddingIndexComplete();
        $payload = [
            'event' => 'document_created',
            'document_id' => 46,
            'modified' => '2026-05-08T15:00:00Z',
        ];
        $starter = $this->mock(DocumentPipelineStarter::class);
        $starter->shouldReceive('start')->once()->andThrow(new RuntimeException('database interrupted'));

        $this->postJson(route('api.webhooks.paperless'), $payload)
            ->assertStatus(503)
            ->assertJson(['status' => 'pipeline_start_failed', 'duplicate' => false]);
        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame(WebhookDelivery::STATUS_RECEIVED, $delivery->status);
        $this->assertDatabaseCount('pipeline_runs', 0);

        $this->app->instance(DocumentPipelineStarter::class, new DocumentPipelineStarter);
        app('router')->getRoutes()->getByName('api.webhooks.paperless')->flushController();
        $this->postJson(route('api.webhooks.paperless'), $payload)
            ->assertOk()
            ->assertJson(['duplicate' => true, 'pipeline_outcome' => 'created']);

        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $delivery->fresh()->status);
        $this->assertDatabaseHas('pipeline_runs', [
            'webhook_delivery_id' => $delivery->id,
            'status' => PipelineRun::STATUS_QUEUED,
        ]);
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

    public function test_both_webhook_aliases_fail_closed_without_effective_secret(): void
    {
        Config::set('archibot.paperless_webhook_secret', '');
        $this->withHeader('X-Webhook-Secret', '')->postJson(route('webhook.paperless'), ['document_id' => 41])
            ->assertForbidden()->assertJson(['detail' => 'Invalid webhook secret']);
        $this->withHeader('X-Webhook-Secret', 'provided-but-not-configured')
            ->postJson(route('api.webhooks.paperless'), ['document_id' => 42])
            ->assertForbidden()->assertJson(['detail' => 'Invalid webhook secret']);

        $this->assertIngressWasNotPersisted();
    }

    public function test_known_placeholder_secrets_fail_closed_for_deployment_and_stored_configuration(): void
    {
        foreach (['<generate-a-unique-random-secret>', '<generate-a-random-secret>', 'change-me'] as $placeholder) {
            Config::set('archibot.paperless_webhook_secret', $placeholder);
            RateLimiter::clear(ValidatePaperlessWebhookRequest::rateLimitKey('127.0.0.1'));

            $this->withHeader('X-Webhook-Secret', $placeholder)
                ->postJson(route('api.webhooks.paperless'), ['document_id' => 42])
                ->assertForbidden();
        }

        Config::set('archibot.paperless_webhook_secret', 'valid-deployment-secret');
        AppSetting::put('webhook.secret', '<generate-a-unique-random-secret>', encrypted: true);
        RateLimiter::clear(ValidatePaperlessWebhookRequest::rateLimitKey('127.0.0.1'));

        $this->withHeader('X-Webhook-Secret', '<generate-a-unique-random-secret>')
            ->postJson(route('webhook.paperless'), ['document_id' => 43])
            ->assertForbidden();

        $this->assertIngressWasNotPersisted();
    }

    public function test_both_webhook_aliases_reject_missing_and_wrong_secrets_with_same_response(): void
    {
        Config::set('archibot.paperless_webhook_secret', 'configured-secret');

        foreach (['webhook.paperless', 'api.webhooks.paperless'] as $routeName) {
            $responses = [
                $this->withHeader('X-Webhook-Secret', '')
                    ->postJson(route($routeName), ['document_id' => 42]),
                $this->withHeader('X-Webhook-Secret', 'x')
                    ->postJson(route($routeName), ['document_id' => 42]),
                $this->withHeader('X-Webhook-Secret', 'configured-secrex')
                    ->postJson(route($routeName), ['document_id' => 42]),
            ];

            foreach ($responses as $response) {
                $response->assertForbidden()->assertExactJson(['detail' => 'Invalid webhook secret']);
            }
        }
        $this->assertIngressWasNotPersisted();
        $this->assertStringContainsString('hash_equals($configuredSecret, $providedSecret)', file_get_contents(app_path('Http/Middleware/ValidatePaperlessWebhookRequest.php')));
    }

    public function test_both_webhook_aliases_accept_valid_deployment_secret(): void
    {
        Config::set('archibot.paperless_webhook_secret', 'configured-secret');

        foreach (['webhook.paperless', 'api.webhooks.paperless'] as $index => $routeName) {
            $this->withHeader('X-Webhook-Secret', 'configured-secret')
                ->postJson(route($routeName), ['document_id' => 42 + $index])
                ->assertOk();
        }
    }

    public function test_event_webhook_requires_secret_from_app_settings(): void
    {
        Config::set('archibot.paperless_webhook_secret', 'env-secret');
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

    public function test_oversized_declared_content_length_is_rejected_before_body_parsing_or_persistence(): void
    {
        Config::set('archibot.paperless_webhook_max_bytes', 32);

        $this->call('POST', route('webhook.paperless'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '33',
            'HTTP_X_WEBHOOK_SECRET' => self::WEBHOOK_SECRET,
        ], '{"marker":"small"}')
            ->assertStatus(413)
            ->assertExactJson(['detail' => 'Webhook payload too large']);

        $this->assertIngressWasNotPersisted();
    }

    public function test_actual_oversized_body_cannot_bypass_limit_with_missing_or_false_content_length(): void
    {
        Config::set('archibot.paperless_webhook_max_bytes', 1024);
        $body = json_encode(['marker' => str_repeat('x', 1024 * 1024)], JSON_THROW_ON_ERROR);

        foreach ([null, '1'] as $declaredLength) {
            $server = [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SECRET' => self::WEBHOOK_SECRET,
            ];
            if ($declaredLength !== null) {
                $server['CONTENT_LENGTH'] = $declaredLength;
            }

            $this->call('POST', route('api.webhooks.paperless'), [], [], [], $server, $body)
                ->assertStatus(413)
                ->assertExactJson(['detail' => 'Webhook payload too large']);
        }

        $this->assertIngressWasNotPersisted();
    }

    public function test_bounded_body_reader_rewinds_valid_json_for_controller_parsing(): void
    {
        Config::set('archibot.paperless_webhook_max_bytes', 256);
        $body = json_encode(['event' => 'document_updated', 'document_id' => 81], JSON_THROW_ON_ERROR);

        $this->call('POST', route('api.webhooks.paperless'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SECRET' => self::WEBHOOK_SECRET,
        ], $body)->assertOk()->assertJson([
            'document_id' => 81,
            'webhook_action' => 'refresh_embedding',
        ]);

        $this->assertDatabaseHas('webhook_deliveries', ['paperless_document_id' => 81]);
        $middleware = file_get_contents(app_path('Http/Middleware/ValidatePaperlessWebhookRequest.php'));
        $this->assertStringContainsString('getContent(true)', $middleware);
        $this->assertStringNotContainsString('strlen($request->getContent())', $middleware);
    }

    public function test_invalid_secrets_share_global_rate_limit_and_limited_attempt_is_not_persisted(): void
    {
        Config::set('archibot.paperless_webhook_rate_limit_per_minute', 2);

        $this->withHeader('X-Webhook-Secret', '')
            ->postJson(route('webhook.paperless'), ['marker' => 'invalid-one'])
            ->assertForbidden();
        $this->withHeader('X-Webhook-Secret', 'wrong')
            ->postJson(route('api.webhooks.paperless'), ['marker' => 'invalid-two'])
            ->assertForbidden();
        $response = $this->withHeader('X-Webhook-Secret', 'still-wrong')
            ->postJson(route('webhook.paperless'), ['marker' => 'limited'])
            ->assertTooManyRequests()
            ->assertExactJson(['detail' => 'Webhook rate limit exceeded']);

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertIngressWasNotPersisted();
    }

    public function test_webhook_rate_limit_is_shared_by_alias_and_does_not_persist_limited_request(): void
    {
        Config::set('archibot.paperless_webhook_rate_limit_per_minute', 2);
        $this->markEmbeddingIndexComplete();

        $this->postJson(route('webhook.paperless'), ['event' => 'document_created', 'document_id' => 61])->assertOk();
        $this->postJson(route('api.webhooks.paperless'), ['event' => 'document_created', 'document_id' => 62])->assertOk();
        $this->postJson(route('api.webhooks.paperless'), ['event' => 'document_created', 'document_id' => 63])->assertTooManyRequests();

        $this->assertDatabaseCount('webhook_deliveries', 2);
        $this->assertDatabaseCount('pipeline_runs', 2);
    }

    public function test_webhook_rate_limit_isolated_by_client_and_can_be_reset(): void
    {
        Config::set('archibot.paperless_webhook_rate_limit_per_minute', 1);
        $this->markEmbeddingIndexComplete();

        $this->postJson(route('api.webhooks.paperless'), ['event' => 'document_created', 'document_id' => 71])->assertOk();
        $this->postJson(route('api.webhooks.paperless'), ['event' => 'document_created', 'document_id' => 72])->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])
            ->postJson(route('api.webhooks.paperless'), ['event' => 'document_created', 'document_id' => 73])
            ->assertOk();

        $localClientKey = ValidatePaperlessWebhookRequest::rateLimitKey('127.0.0.1');
        $this->assertSame('paperless-webhook:'.hash('sha256', '127.0.0.1'), $localClientKey);
        RateLimiter::clear($localClientKey);
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson(route('api.webhooks.paperless'), ['event' => 'document_created', 'document_id' => 74])
            ->assertOk();

        $this->assertDatabaseCount('webhook_deliveries', 3);
    }

    public function test_development_bypass_requires_explicit_flag_and_local_environment(): void
    {
        Config::set('archibot.paperless_webhook_secret', '');
        Config::set('archibot.paperless_webhook_development_bypass', true);
        Config::set('archibot.paperless_webhook_rate_limit_per_minute', 1);
        $this->app->detectEnvironment(fn (): string => 'local');

        $this->withHeader('X-Webhook-Secret', '')
            ->postJson(route('api.webhooks.paperless'), [])
            ->assertOk();
        $this->withHeader('X-Webhook-Secret', '')
            ->postJson(route('webhook.paperless'), ['marker' => 'limited-bypass'])
            ->assertTooManyRequests();

        $this->assertDatabaseCount('webhook_deliveries', 1);
    }

    public function test_development_bypass_flag_fails_closed_in_testing_and_production(): void
    {
        Config::set('archibot.paperless_webhook_secret', '');
        Config::set('archibot.paperless_webhook_development_bypass', true);

        $this->withHeader('X-Webhook-Secret', '')
            ->postJson(route('api.webhooks.paperless'), [])
            ->assertForbidden();

        $this->app->detectEnvironment(fn (): string => 'production');
        $this->withHeader('X-Webhook-Secret', '')
            ->postJson(route('webhook.paperless'), [])
            ->assertForbidden();

        $this->assertIngressWasNotPersisted();
    }

    public function test_event_webhook_rejects_and_persists_missing_document_id_for_diagnostics(): void
    {
        $this->postJson(route('api.webhooks.paperless'), ['event' => 'document_created'])
            ->assertStatus(422)
            ->assertJson([
                'detail' => 'Could not extract document_id from payload',
                'status' => WebhookDelivery::STATUS_FAILED_PERMANENT,
                'duplicate' => false,
            ]);

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertNull($delivery->paperless_document_id);
        $this->assertSame(WebhookDelivery::STATUS_FAILED_PERMANENT, $delivery->status);
        $this->assertSame('missing_document_id', $delivery->error);
        $this->assertNull($delivery->normalized_payload['paperless_document_id']);
        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'webhook.invalid_payload',
        ]);
        $this->assertDatabaseCount('pipeline_runs', 0);
    }

    private function assertIngressWasNotPersisted(): void
    {
        $this->assertDatabaseCount('webhook_deliveries', 0);
        $this->assertDatabaseCount('commands', 0);
        $this->assertDatabaseCount('pipeline_events', 0);
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
