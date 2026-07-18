<?php

namespace Tests\Feature;

use App\Jobs\RunPythonActorJob;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmbeddingIndexControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_embedding_index_build_command(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('embedding-index.build'), ['limit' => 10])
            ->assertRedirect();

        $command = Command::query()->firstOrFail();
        $this->assertSame('embedding_index_build', $command->type);
        $this->assertSame('queued', $command->status);
        $this->assertSame(10, $command->payload['limit']);
        $this->assertSame($admin->id, $command->created_by_user_id);

        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $queued): bool => $queued->actorName === 'build_embedding_index'
            && $queued->commandId === $command->id);

        $this->assertDatabaseHas('pipeline_events', [
            'command_id' => $command->id,
            'event_type' => 'job_control.embedding_build_requested',
        ]);
        $this->assertDatabaseHas('pipeline_events', [
            'command_id' => $command->id,
            'event_type' => 'job_control.embedding_build_actor_queued',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'embedding_index.build_requested',
        ]);
    }

    public function test_maintenance_quick_control_can_queue_embedding_index_build_command(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('embedding-index.build'), ['ui_surface' => 'maintenance_quick_controls'])
            ->assertRedirect();

        $command = Command::query()->firstOrFail();
        $this->assertSame(Command::TYPE_EMBEDDING_INDEX_BUILD, $command->type);
        $this->assertSame('maintenance_quick_controls', $command->payload['ui_surface']);
    }

    public function test_non_admin_cannot_queue_embedding_index_build_command(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('embedding-index.build'))
            ->assertForbidden();

        $this->assertDatabaseCount('commands', 0);
    }

    public function test_admin_can_mark_embedding_index_stale(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $state = EmbeddingIndexState::query()->create([
            'status' => 'complete',
            'embedding_model' => 'nomic-embed-text',
            'document_count' => 10,
            'embedded_count' => 10,
        ]);

        $this->actingAs($admin)
            ->post(route('embedding-index.mark-stale'))
            ->assertRedirect();

        $state->refresh();
        $this->assertSame('stale', $state->status);
        $this->assertSame('Marked stale by admin.', $state->error);

        $this->assertSame('embedding_index.marked_stale', PipelineEvent::query()->firstOrFail()->event_type);
        $this->assertSame('embedding_index.marked_stale', AuditLog::query()->firstOrFail()->event);
    }

    public function test_non_admin_cannot_mark_embedding_index_stale(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('embedding-index.mark-stale'))
            ->assertForbidden();

        $this->assertDatabaseCount('embedding_index_state', 0);
    }
}
