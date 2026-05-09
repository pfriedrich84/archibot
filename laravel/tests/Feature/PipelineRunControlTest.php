<?php

namespace Tests\Feature;

use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\PipelineItem;
use App\Models\PipelineRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PipelineRunControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_retry_failed_pipeline_run(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_FAILED,
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
            'retry_count' => 2,
            'error_type' => 'RuntimeError',
            'error' => 'boom',
            'finished_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('pipeline-runs.retry', $run))
            ->assertRedirect();

        $run->refresh();
        $this->assertSame(PipelineRun::STATUS_PENDING, $run->status);
        $this->assertSame(3, $run->retry_count);
        $this->assertSame('manual_admin_retry', $run->retry_reason);
        $this->assertSame('manual', $run->retry_mode);
        $this->assertNull($run->error_type);
        $this->assertNull($run->finished_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'pipeline_run.retry_queued',
            'target_type' => 'pipeline_run',
            'target_id' => (string) $run->id,
        ]);
    }

    public function test_admin_can_retry_failed_pipeline_items(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_PARTIALLY_FAILED,
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
            'progress_failed' => 1,
            'retry_count' => 2,
            'error_type' => 'RuntimeError',
            'error' => 'boom',
            'finished_at' => now(),
        ]);
        $item = PipelineItem::query()->create([
            'pipeline_run_id' => $run->id,
            'paperless_document_id' => 123,
            'item_type' => 'classification',
            'status' => 'failed',
            'attempt' => 1,
            'error' => 'bad response',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('pipeline-runs.retry-failed-items', $run))
            ->assertRedirect();

        $run->refresh();
        $item->refresh();
        $this->assertSame(PipelineRun::STATUS_PENDING, $run->status);
        $this->assertSame('retry_failed_items', $run->progress_current_phase);
        $this->assertSame(3, $run->retry_count);
        $this->assertSame('manual_admin_retry_failed_items', $run->retry_reason);
        $this->assertSame('manual', $run->retry_mode);
        $this->assertNull($run->error_type);
        $this->assertNull($run->finished_at);
        $this->assertSame('pending', $item->status);
        $this->assertSame(2, $item->attempt);
        $this->assertSame('manual_admin_retry_failed_items', $item->retry_reason);
        $this->assertSame('manual', $item->retry_mode);
        $this->assertNull($item->error);
        $this->assertNull($item->finished_at);
        $this->assertSame('job_control.retry_failed_items_requested', PipelineEvent::query()->firstOrFail()->event_type);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'pipeline_run.retry_failed_items_queued',
            'target_type' => 'pipeline_run',
            'target_id' => (string) $run->id,
        ]);
    }

    public function test_admin_retry_blocks_document_run_when_embedding_index_is_not_complete(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        EmbeddingIndexState::query()->create(['status' => 'stale']);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_FAILED,
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
            'retry_count' => 2,
            'error_type' => 'RuntimeError',
            'error' => 'boom',
            'finished_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('pipeline-runs.retry', $run))
            ->assertRedirect();

        $run->refresh();
        $this->assertSame(PipelineRun::STATUS_BLOCKED, $run->status);
        $this->assertSame('blocked', $run->progress_current_phase);
        $this->assertSame('Waiting for embedding index to complete.', $run->progress_message);
        $this->assertSame('embedding_index_not_ready', $run->error_type);
        $this->assertSame('Waiting for embedding index to complete.', $run->error);
        $this->assertNull($run->finished_at);
    }

    public function test_retry_failed_items_blocks_document_run_when_embedding_index_is_not_complete(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        EmbeddingIndexState::query()->create(['status' => 'pending']);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_PARTIALLY_FAILED,
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
            'progress_failed' => 1,
            'retry_count' => 2,
            'error_type' => 'RuntimeError',
            'error' => 'boom',
            'finished_at' => now(),
        ]);
        PipelineItem::query()->create([
            'pipeline_run_id' => $run->id,
            'paperless_document_id' => 123,
            'item_type' => 'classification',
            'status' => 'failed',
            'attempt' => 1,
            'error' => 'bad response',
            'finished_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('pipeline-runs.retry-failed-items', $run))
            ->assertRedirect();

        $run->refresh();
        $this->assertSame(PipelineRun::STATUS_BLOCKED, $run->status);
        $this->assertSame('blocked', $run->progress_current_phase);
        $this->assertSame('Waiting for embedding index to complete.', $run->progress_message);
        $this->assertSame('embedding_index_not_ready', $run->error_type);
        $this->assertSame('Waiting for embedding index to complete.', $run->error);
    }

    public function test_non_admin_cannot_retry_failed_pipeline_items(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_PARTIALLY_FAILED,
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
        ]);
        PipelineItem::query()->create([
            'pipeline_run_id' => $run->id,
            'paperless_document_id' => 123,
            'item_type' => 'classification',
            'status' => 'failed',
        ]);

        $this->actingAs($user)
            ->post(route('pipeline-runs.retry-failed-items', $run))
            ->assertForbidden();

        $this->assertSame(PipelineRun::STATUS_PARTIALLY_FAILED, $run->refresh()->status);
    }

    public function test_retry_failed_pipeline_items_requires_failed_items(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_PARTIALLY_FAILED,
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
        ]);

        $this->actingAs($admin)
            ->post(route('pipeline-runs.retry-failed-items', $run))
            ->assertStatus(409);

        $this->assertSame(PipelineRun::STATUS_PARTIALLY_FAILED, $run->refresh()->status);
    }

    public function test_admin_can_request_cancel_for_active_pipeline_run(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_RUNNING,
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
        ]);

        $this->actingAs($admin)
            ->post(route('pipeline-runs.cancel', $run))
            ->assertRedirect();

        $run->refresh();
        $this->assertSame(PipelineRun::STATUS_CANCEL_REQUESTED, $run->status);
        $this->assertSame('cancel_requested', $run->error_type);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'pipeline_run.cancel_requested',
            'target_type' => 'pipeline_run',
            'target_id' => (string) $run->id,
        ]);
    }

    public function test_non_admin_cannot_control_pipeline_run(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_FAILED,
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
        ]);

        $this->actingAs($user)
            ->post(route('pipeline-runs.retry', $run))
            ->assertForbidden();

        $this->assertSame(PipelineRun::STATUS_FAILED, $run->refresh()->status);
    }

    public function test_invalid_pipeline_control_transition_returns_conflict(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_SUCCEEDED,
            'trigger_source' => 'webhook',
            'paperless_document_id' => 123,
        ]);

        $this->actingAs($admin)
            ->post(route('pipeline-runs.retry', $run))
            ->assertStatus(409);
    }
}
