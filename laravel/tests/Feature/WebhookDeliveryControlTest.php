<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PipelineEvent;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebhookDeliveryControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_webhook_delivery_index(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->webhookDelivery([
            'event_type' => 'document.created',
            'paperless_document_id' => 99,
            'status' => WebhookDelivery::STATUS_PROCESSED,
            'dedupe_key' => 'dedupe-processed',
            'raw_payload' => ['document_id' => 99, 'title' => 'Invoice'],
            'headers' => ['x-paperless-event' => 'document.created'],
            'processed_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('webhook-deliveries.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('webhooks/Index')
                ->where('isAdmin', false)
                ->has('deliveries.data', 1)
                ->where('deliveries.data.0.event_type', 'document.created')
                ->where('deliveries.data.0.paperless_document_id', 99)
                ->where('deliveries.data.0.status', WebhookDelivery::STATUS_PROCESSED)
                ->where('deliveries.data.0.dedupe_key', 'dedupe-processed')
                ->where('deliveries.data.0.payload_summary.0.key', 'document_id')
                ->where('deliveries.data.0.header_summary.0.key', 'x-paperless-event')
                ->where('deliveries.data.0.can_retry', false)
                ->where('deliveries.data.0.can_dismiss', false)
            );
    }

    public function test_failed_delivery_lists_admin_action_data_and_detail(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $delivery = $this->webhookDelivery([
            'status' => WebhookDelivery::STATUS_FAILED,
            'error' => 'Absurd unavailable',
            'normalized_payload' => ['document_id' => 42, 'event' => 'updated'],
            'headers' => ['x-request-id' => 'req-123'],
        ]);
        PipelineEvent::query()->create([
            'webhook_delivery_id' => $delivery->id,
            'event_type' => 'paperless.delivery.failed',
            'paperless_document_id' => 42,
            'level' => 'error',
            'message' => 'Delivery failed.',
            'payload' => ['reason' => 'Absurd unavailable'],
            'created_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('webhook-deliveries.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('webhooks/Index')
                ->where('isAdmin', true)
                ->where('deliveries.data.0.id', $delivery->id)
                ->where('deliveries.data.0.status', WebhookDelivery::STATUS_FAILED)
                ->where('deliveries.data.0.error', 'Absurd unavailable')
                ->where('deliveries.data.0.can_retry', true)
                ->where('deliveries.data.0.can_dismiss', true)
                ->where('deliveries.data.0.retry_url', route('webhook-deliveries.retry', $delivery))
                ->where('deliveries.data.0.dismiss_url', route('webhook-deliveries.dismiss', $delivery))
            );

        $this->actingAs($admin)
            ->get(route('webhook-deliveries.show', $delivery))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('webhooks/Show')
                ->where('delivery.id', $delivery->id)
                ->where('delivery.raw_payload.document_id', 42)
                ->where('delivery.normalized_payload.event', 'updated')
                ->where('delivery.headers.x-request-id', 'req-123')
                ->where('delivery.pipeline_events.0.event_type', 'paperless.delivery.failed')
                ->where('delivery.pipeline_events.0.payload.reason', 'Absurd unavailable')
                ->where('delivery.can_retry', true)
                ->where('delivery.can_dismiss', true)
            );
    }

    public function test_guest_cannot_view_webhook_delivery_index(): void
    {
        $this->get(route('webhook-deliveries.index'))->assertRedirect(route('login'));
    }

    public function test_admin_can_retry_failed_webhook_delivery(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $delivery = $this->webhookDelivery([
            'status' => WebhookDelivery::STATUS_FAILED,
            'error' => 'Absurd unavailable',
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
