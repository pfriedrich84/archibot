<?php

namespace Tests\Feature\Webhooks;

use App\Models\PipelineEvent;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PaperlessEventWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_webhook_persists_delivery_and_received_event(): void
    {
        $this->postJson(route('api.webhooks.paperless'), [
            'event' => 'document_created',
            'document' => ['modified' => '2026-05-08T12:00:00Z'],
            'object' => ['id' => 42],
        ])->assertOk()->assertJson([
            'status' => 'queued',
            'duplicate' => false,
            'document_id' => 42,
        ]);

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame('paperless', $delivery->source);
        $this->assertSame('document_created', $delivery->event_type);
        $this->assertSame(42, $delivery->paperless_document_id);
        $this->assertSame(WebhookDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertSame('2026-05-08T12:00:00Z', $delivery->normalized_payload['paperless_modified']);

        $event = PipelineEvent::query()->firstOrFail();
        $this->assertSame('webhook.received', $event->event_type);
        $this->assertSame($delivery->id, $event->webhook_delivery_id);
        $this->assertSame(42, $event->paperless_document_id);
    }

    public function test_event_webhook_redacts_persisted_secrets_but_hashes_original_payload(): void
    {
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

    public function test_event_webhook_deduplicates_identical_delivery(): void
    {
        $payload = [
            'event' => 'document_updated',
            'object' => ['id' => 7, 'modified' => '2026-05-08T13:00:00Z'],
        ];

        $this->postJson(route('api.webhooks.paperless'), $payload)->assertOk()->assertJson([
            'status' => 'queued',
            'duplicate' => false,
        ]);
        $this->postJson(route('api.webhooks.paperless'), $payload)->assertOk()->assertJson([
            'status' => 'duplicate',
            'duplicate' => true,
        ]);

        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseHas('pipeline_events', ['event_type' => 'webhook.duplicate']);
    }

    public function test_event_webhook_records_deferred_enqueue_when_direct_enqueue_fails(): void
    {
        Config::set('archibot.webhook_direct_enqueue_enabled', true);
        Config::set('archibot.python_binary', 'python-test');
        Process::fake(['*' => Process::result(errorOutput: 'secret-token should not be stored', exitCode: 1)]);

        $this->withHeader('X-Webhook-Secret', 'top-secret')
            ->postJson(route('api.webhooks.paperless'), [
                'event' => 'document_created',
                'document_id' => 42,
            ])
            ->assertOk()
            ->assertJson(['status' => 'queued', 'duplicate' => false]);

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame(WebhookDelivery::STATUS_QUEUED, $delivery->status);
        $event = PipelineEvent::query()
            ->where('event_type', 'webhook.enqueue_deferred')
            ->firstOrFail();

        $this->assertSame($delivery->id, $event->webhook_delivery_id);
        $this->assertSame('process_failed', $event->payload['error_type']);
        $this->assertSame(1, $event->payload['exit_code']);
        $this->assertStringNotContainsString('top-secret', json_encode($event->payload));
        $this->assertStringNotContainsString('secret-token', json_encode($event->payload));

        Process::assertRan(fn ($process): bool => $process->command === [
            'python-test',
            '-m',
            'app.event_worker',
            'enqueue-webhook',
            '--delivery-id',
            (string) $delivery->id,
        ]);
    }

    public function test_event_webhook_records_enqueue_requested_when_direct_enqueue_succeeds(): void
    {
        Config::set('archibot.webhook_direct_enqueue_enabled', true);
        Config::set('archibot.python_binary', 'python-test');
        Process::fake(['*' => Process::result()]);

        $this->postJson(route('api.webhooks.paperless'), [
            'event' => 'document_created',
            'document_id' => 42,
        ])->assertOk();

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'webhook.enqueue_requested',
        ]);
        $this->assertSame(WebhookDelivery::STATUS_QUEUED, $delivery->fresh()->status);
    }

    public function test_event_webhook_duplicate_does_not_attempt_direct_enqueue(): void
    {
        Config::set('archibot.webhook_direct_enqueue_enabled', true);
        Config::set('archibot.python_binary', 'python-test');
        Process::fake(['*' => Process::result()]);

        $payload = ['event' => 'document_updated', 'document_id' => 7];

        $this->postJson(route('api.webhooks.paperless'), $payload)->assertOk();
        $this->postJson(route('api.webhooks.paperless'), $payload)->assertOk()->assertJson([
            'status' => 'duplicate',
            'duplicate' => true,
        ]);

        Process::assertRanTimes(fn ($process): bool => $process->command === [
            'python-test',
            '-m',
            'app.event_worker',
            'enqueue-webhook',
            '--delivery-id',
            (string) WebhookDelivery::query()->firstOrFail()->id,
        ], 1);
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseCount('pipeline_events', 3);
    }

    public function test_event_webhook_requires_secret_when_configured(): void
    {
        Config::set('archibot.paperless_webhook_secret', 'secret');

        $this->postJson(route('api.webhooks.paperless'), ['document_id' => 42])
            ->assertForbidden()
            ->assertJson(['detail' => 'Invalid webhook secret']);

        $this->withHeader('X-Webhook-Secret', 'secret')
            ->postJson(route('api.webhooks.paperless'), ['document_id' => 42])
            ->assertOk();
    }

    public function test_event_webhook_rejects_missing_document_id(): void
    {
        $this->postJson(route('api.webhooks.paperless'), ['event' => 'document_created'])
            ->assertStatus(422)
            ->assertJson(['detail' => 'Could not extract document_id from payload']);

        $this->assertDatabaseCount('webhook_deliveries', 0);
    }
}
