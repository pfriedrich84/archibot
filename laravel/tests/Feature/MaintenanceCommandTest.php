<?php

namespace Tests\Feature;

use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertSame('queued', $command->status);
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

    public function test_maintenance_quick_control_can_queue_forced_poll_reconciliation(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('maintenance.poll'), [
                'force' => '1',
                'ui_surface' => 'maintenance_quick_controls',
            ])
            ->assertRedirect();

        $command = Command::query()->firstOrFail();
        $this->assertSame('poll_reconciliation', $command->type);
        $this->assertTrue($command->payload['force']);
        $this->assertSame('maintenance_quick_controls', $command->payload['ui_surface']);
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
        $this->assertSame('queued', $command->status);
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

    public function test_maintenance_quick_control_can_queue_reindex_command(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('maintenance.reindex'), ['ui_surface' => 'maintenance_quick_controls'])
            ->assertRedirect();

        $command = Command::query()->firstOrFail();
        $this->assertSame('reindex', $command->type);
        $this->assertSame('maintenance_quick_controls', $command->payload['ui_surface']);
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

    public function test_cli_reset_clears_postgresql_operational_state(): void
    {
        DB::table('embedding_index_state')->insert([
            'status' => 'complete',
            'embedding_model' => 'nomic-embed-text',
            'document_count' => 1,
            'embedded_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('document_embeddings')->insert([
            'paperless_document_id' => 123,
            'content_hash' => 'hash',
            'embedding_model' => 'nomic-embed-text',
            'dimensions' => 3,
            'embedding' => json_encode([0.1, 0.2, 0.3]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('audit_logs')->insert([
            'event' => 'test.event',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('archibot:reset', ['--yes' => true])
            ->expectsOutputToContain('Archibot Laravel reset complete.')
            ->assertSuccessful();

        $this->assertDatabaseCount('embedding_index_state', 0);
        $this->assertDatabaseCount('document_embeddings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
