<?php

namespace Tests\Feature;

use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_poll_reconciliation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('maintenance.poll'), ['limit' => 25])
            ->assertRedirect();

        $command = Command::query()->firstOrFail();
        $this->assertSame('poll_reconciliation', $command->type);
        $this->assertSame('pending', $command->status);
        $this->assertSame(['limit' => 25], $command->payload);
        $this->assertSame($admin->id, $command->created_by_user_id);

        $this->assertDatabaseHas('pipeline_events', [
            'command_id' => $command->id,
            'event_type' => 'job_control.poll_reconciliation_requested',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'maintenance.poll_reconciliation_requested',
        ]);
    }

    public function test_non_admin_cannot_queue_poll_reconciliation(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('maintenance.poll'))
            ->assertForbidden();

        $this->assertDatabaseCount('commands', 0);
    }

    public function test_admin_can_queue_reindex_and_close_embedding_gate(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $state = EmbeddingIndexState::query()->create([
            'status' => 'complete',
            'embedding_model' => 'nomic-embed-text',
            'document_count' => 10,
            'embedded_count' => 10,
        ]);

        $this->actingAs($admin)
            ->post(route('maintenance.reindex'), ['limit' => 50])
            ->assertRedirect();

        $command = Command::query()->firstOrFail();
        $this->assertSame('reindex', $command->type);
        $this->assertSame('pending', $command->status);
        $this->assertSame(['limit' => 50], $command->payload);
        $this->assertSame($admin->id, $command->created_by_user_id);

        $state->refresh();
        $this->assertSame('stale', $state->status);
        $this->assertSame('Reindex requested by admin.', $state->error);

        $this->assertDatabaseHas('pipeline_events', [
            'command_id' => $command->id,
            'event_type' => 'job_control.reindex_requested',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'event' => 'maintenance.reindex_requested',
        ]);
    }

    public function test_non_admin_cannot_queue_reindex(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->post(route('maintenance.reindex'))
            ->assertForbidden();

        $this->assertDatabaseCount('commands', 0);
        $this->assertDatabaseCount('embedding_index_state', 0);
    }
}
