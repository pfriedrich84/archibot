<?php

namespace Tests\Feature;

use App\Models\ActorExecution;
use App\Models\Command;
use App\Models\PipelineRun;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_exposes_durable_operational_state_with_running_operations_only(): void
    {
        $user = User::factory()->create(['is_admin' => true]);

        Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_QUEUED,
        ]);
        PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_RUNNING,
            'trigger_source' => 'manual',
            'paperless_document_id' => 42,
        ]);
        ActorExecution::query()->create([
            'actor_name' => 'handle_document_pipeline',
            'status' => ActorExecution::STATUS_RUNNING,
        ]);
        WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document_added',
            'status' => WebhookDelivery::STATUS_PROCESSED,
            'dedupe_key' => 'dashboard-delivery',
            'payload_hash' => hash('sha256', 'dashboard-delivery'),
            'raw_payload' => [],
            'received_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('maintenance.pending_poll_commands', 1)
                ->where('maintenance.document_processing_active', true)
                ->missing('lastSuccessfulRetiredJob')
                ->missing('recentRetiredJobs')
                ->has('activeOperations.items', 1)
                ->where('activeOperations.items.0.status', PipelineRun::STATUS_RUNNING)
                ->where('activeOperations.summary.running', 1)
                ->where('activeOperations.summary.queued', 0)
                ->has('recentPipelineRuns', 1)
                ->has('recentActorExecutions', 1)
                ->where('recentActorExecutions.0.actor_name', 'handle_document_pipeline')
                ->has('recentWebhookDeliveries', 1)
                ->where('recentWebhookDeliveries.0.event_type', 'document_added')
            );
    }
}
