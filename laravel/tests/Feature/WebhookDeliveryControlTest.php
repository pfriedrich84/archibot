<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PipelineEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookDeliveryControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_retry_failed_webhook_delivery(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $delivery = $this->webhookDelivery([
            'status' => WebhookDelivery::STATUS_FAILED,
            'error' => 'RabbitMQ unavailable',
            'processed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('webhook-deliveries.retry', $delivery))
            ->assertRedirect();

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_QUEUED, $delivery->status);
        $this->assertNull($delivery->error);
        $this->assertNull($delivery->processed_at);
        $this->assertSame('job_control.webhook_retry_requested', PipelineEvent::query()->firstOrFail()->event_type);
        $this->assertSame('webhook_delivery.retry_queued', AuditLog::query()->firstOrFail()->event);
    }

    public function test_admin_can_dismiss_failed_webhook_delivery(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $delivery = $this->webhookDelivery([
            'status' => WebhookDelivery::STATUS_FAILED_PERMANENT,
            'error' => 'Invalid payload',
        ]);

        $this->actingAs($admin)
            ->post(route('webhook-deliveries.dismiss', $delivery))
            ->assertRedirect();

        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_DISMISSED, $delivery->status);
        $this->assertNull($delivery->error);
        $this->assertNotNull($delivery->processed_at);
        $this->assertSame('job_control.webhook_failure_dismissed', PipelineEvent::query()->firstOrFail()->event_type);
        $this->assertSame('webhook_delivery.failure_dismissed', AuditLog::query()->firstOrFail()->event);
    }

    public function test_non_admin_cannot_retry_or_dismiss_webhook_delivery(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $retryDelivery = $this->webhookDelivery([
            'status' => WebhookDelivery::STATUS_FAILED,
            'error' => 'Paperless unavailable',
        ]);
        $dismissDelivery = $this->webhookDelivery([
            'status' => WebhookDelivery::STATUS_BLOCKED,
            'error' => 'embedding_index_not_ready',
            'dedupe_key' => 'dedupe-2',
        ]);

        $this->actingAs($user)
            ->post(route('webhook-deliveries.retry', $retryDelivery))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('webhook-deliveries.dismiss', $dismissDelivery))
            ->assertForbidden();

        $this->assertSame(WebhookDelivery::STATUS_FAILED, $retryDelivery->fresh()->status);
        $this->assertSame(WebhookDelivery::STATUS_BLOCKED, $dismissDelivery->fresh()->status);
        $this->assertDatabaseCount('pipeline_events', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_processed_webhook_delivery_is_not_retryable_or_dismissible(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $delivery = $this->webhookDelivery([
            'status' => WebhookDelivery::STATUS_PROCESSED,
        ]);

        $this->actingAs($admin)
            ->post(route('webhook-deliveries.retry', $delivery))
            ->assertStatus(409);
        $this->actingAs($admin)
            ->post(route('webhook-deliveries.dismiss', $delivery))
            ->assertStatus(409);

        $this->assertSame(WebhookDelivery::STATUS_PROCESSED, $delivery->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function webhookDelivery(array $overrides = []): WebhookDelivery
    {
        return WebhookDelivery::query()->create(array_merge([
            'source' => 'paperless',
            'event_type' => 'document.updated',
            'paperless_document_id' => 42,
            'dedupe_key' => 'dedupe-1',
            'payload_hash' => str_repeat('b', 64),
            'raw_payload' => ['document_id' => 42],
            'status' => WebhookDelivery::STATUS_FAILED,
            'received_at' => now(),
        ], $overrides));
    }
}
