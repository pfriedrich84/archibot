<?php

namespace Tests\Feature\Pipeline;

use App\Jobs\RunPythonActorJob;
use App\Models\PipelineEvent;
use App\Models\WebhookDelivery;
use App\Services\Actors\PythonActorRunner;
use App\Services\Pipeline\PipelineRecoveryDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PipelineRecoveryDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_recovery_scan_redispatches_queued_non_process_webhook_deliveries(): void
    {
        Queue::fake();

        $refresh = $this->webhookDelivery([
            'event_type' => 'document_updated',
            'paperless_document_id' => 42,
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'],
        ]);
        $delete = $this->webhookDelivery([
            'event_type' => 'document_deleted',
            'paperless_document_id' => 43,
            'normalized_payload' => ['webhook_action' => 'delete_embedding'],
        ]);
        $process = $this->webhookDelivery([
            'event_type' => 'document_created',
            'paperless_document_id' => 44,
            'normalized_payload' => ['webhook_action' => 'process_document'],
        ]);
        $failed = $this->webhookDelivery([
            'event_type' => 'document_updated',
            'paperless_document_id' => 45,
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'],
            'status' => WebhookDelivery::STATUS_FAILED,
        ]);

        $count = app(PipelineRecoveryDispatcher::class)->recoverQueuedWebhookDeliveries(limit: 10);

        $this->assertSame(2, $count);
        Queue::assertPushed(RunPythonActorJob::class, 2);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK
            && $job->commandId === $refresh->id);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_HANDLE_PAPERLESS_WEBHOOK
            && $job->commandId === $delete->id);
        Queue::assertNotPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $process->id
            || $job->commandId === $failed->id);

        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $refresh->id,
            'event_type' => 'recovery.webhook_actor_redispatched',
            'paperless_document_id' => 42,
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'webhook_delivery_id' => $delete->id,
            'event_type' => 'recovery.webhook_actor_redispatched',
            'paperless_document_id' => 43,
        ]);
        $this->assertDatabaseCount('pipeline_events', 2);
    }

    public function test_recovery_artisan_command_runs_laravel_native_webhook_redispatch(): void
    {
        Queue::fake();

        $delivery = $this->webhookDelivery([
            'event_type' => 'document_updated',
            'paperless_document_id' => 46,
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'],
        ]);

        $this->artisan('archibot:recovery-scan', ['--limit' => 5])
            ->expectsOutput('Recovery scan complete. webhook_deliveries_redispatched=1')
            ->assertSuccessful();

        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->commandId === $delivery->id);
        $this->assertSame('recovery.webhook_actor_redispatched', PipelineEvent::query()->firstOrFail()->event_type);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function webhookDelivery(array $overrides = []): WebhookDelivery
    {
        $documentId = (int) ($overrides['paperless_document_id'] ?? 42);
        $eventType = (string) ($overrides['event_type'] ?? 'document_updated');
        $payload = $overrides['raw_payload'] ?? ['document_id' => $documentId];

        return WebhookDelivery::query()->create(array_merge([
            'source' => 'paperless',
            'event_type' => $eventType,
            'paperless_document_id' => $documentId,
            'dedupe_key' => "paperless:{$eventType}:{$documentId}:".uniqid('', true),
            'payload_hash' => hash('sha256', json_encode($payload) ?: ''),
            'raw_payload' => $payload,
            'normalized_payload' => ['webhook_action' => 'refresh_embedding'],
            'headers' => [],
            'status' => WebhookDelivery::STATUS_QUEUED,
            'request_id' => uniqid('request-', true),
            'received_at' => now(),
        ], $overrides));
    }
}
