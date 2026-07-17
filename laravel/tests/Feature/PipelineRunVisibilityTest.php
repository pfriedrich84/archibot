<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Command;
use App\Models\PipelineEvent;
use App\Models\PipelineItem;
use App\Models\PipelineRun;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PipelineRunVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_users_cannot_view_pipeline_runs_index(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $command = Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_PENDING,
            'payload' => ['limit' => 10],
        ]);
        $delivery = WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document_updated',
            'paperless_document_id' => 123,
            'dedupe_key' => 'paperless:document_updated:123',
            'payload_hash' => hash('sha256', 'payload'),
            'raw_payload' => ['document_id' => 123],
            'status' => WebhookDelivery::STATUS_PROCESSED,
            'received_at' => now(),
        ]);
        $run = PipelineRun::query()->create([
            'command_id' => $command->id,
            'webhook_delivery_id' => $delivery->id,
            'type' => 'document',
            'status' => PipelineRun::STATUS_RUNNING,
            'scope' => 'document:123',
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
            'progress_total' => 3,
            'progress_done' => 1,
            'progress_failed' => 0,
            'progress_current_phase' => 'classification',
        ]);
        PipelineEvent::query()->create([
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $delivery->id,
            'command_id' => $command->id,
            'event_type' => 'pipeline.running',
            'paperless_document_id' => 123,
            'message' => 'Running classification.',
        ]);
        PipelineItem::query()->create([
            'pipeline_run_id' => $run->id,
            'paperless_document_id' => 123,
            'item_type' => PipelineItem::TYPE_CLASSIFICATION,
            'status' => PipelineItem::STATUS_RUNNING,
        ]);

        $this->actingAs($user)
            ->get(route('pipeline-runs.index'))
            ->assertForbidden();
    }

    public function test_pipeline_run_detail_shows_links_events_items_and_audit(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $command = Command::query()->create([
            'type' => 'process_document',
            'status' => Command::STATUS_RUNNING,
            'payload' => ['paperless_document_id' => 456],
            'created_by_user_id' => $admin->id,
        ]);
        $delivery = WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document_updated',
            'paperless_document_id' => 456,
            'dedupe_key' => 'paperless:document_updated:456',
            'payload_hash' => hash('sha256', 'payload-456'),
            'raw_payload' => ['document_id' => 456],
            'status' => WebhookDelivery::STATUS_PROCESSED,
            'request_id' => 'request-456',
            'received_at' => now(),
        ]);
        $run = PipelineRun::query()->create([
            'command_id' => $command->id,
            'webhook_delivery_id' => $delivery->id,
            'type' => 'document',
            'status' => PipelineRun::STATUS_FAILED,
            'scope' => 'document:456',
            'trigger_source' => 'webhook',
            'paperless_document_id' => 456,
            'progress_total' => 2,
            'progress_done' => 1,
            'progress_failed' => 1,
            'progress_current_phase' => 'review',
            'error_type' => 'RuntimeError',
            'error' => 'classification failed',
        ]);
        PipelineEvent::query()->create([
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $delivery->id,
            'command_id' => $command->id,
            'event_type' => 'pipeline.failed',
            'paperless_document_id' => 456,
            'level' => 'error',
            'message' => 'Pipeline failed.',
            'payload' => ['phase' => 'review'],
        ]);
        PipelineItem::query()->create([
            'pipeline_run_id' => $run->id,
            'paperless_document_id' => 456,
            'item_type' => PipelineItem::TYPE_REVIEW_SUGGESTION,
            'status' => PipelineItem::STATUS_FAILED,
            'attempt' => 2,
            'error' => 'bad response',
        ]);
        AuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'event' => 'pipeline_run.retry_queued',
            'target_type' => 'pipeline_run',
            'target_id' => (string) $run->id,
            'metadata' => ['pipeline_run_id' => $run->id, 'command_id' => $command->id],
        ]);

        $this->actingAs($admin)
            ->get(route('pipeline-runs.show', $run))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pipeline-runs/Show')
                ->where('run.id', $run->id)
                ->where('run.status', PipelineRun::STATUS_FAILED)
                ->where('run.progress_current_phase', 'review')
                ->where('run.error_type', 'RuntimeError')
                ->where('run.command.id', $command->id)
                ->where('run.command.metadata.0.key', 'paperless_document_id')
                ->where('run.command.metadata.0.value', 456)
                ->where('run.webhook_delivery.id', $delivery->id)
                ->where('run.webhook_delivery.request_id', 'ref:'.substr(hash('sha256', 'request-456'), 0, 12))
                ->where('run.events.0.event_type', 'pipeline.failed')
                ->where('run.events.0.message', 'Details redacted. Use the status, error type, identifiers and timeline to diagnose or recover this operation.')
                ->where('run.events.0.metadata.0.key', 'phase')
                ->where('run.items.0.item_type', PipelineItem::TYPE_REVIEW_SUGGESTION)
                ->where('run.items.0.error', 'Details redacted. Use the status, error type, identifiers and timeline to diagnose or recover this operation.')
                ->where('run.audit_logs.0.event', 'pipeline_run.retry_queued')
                ->where('run.can_retry', true)
                ->where('isAdmin', true)
            );
    }
}
