<?php

namespace Tests\Feature;

use App\Models\ActorExecution;
use App\Models\Command;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StatsAndErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_use_durable_commands_and_pipeline_sources(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        ReviewSuggestion::factory()->create(['status' => ReviewSuggestion::STATUS_PENDING]);
        Command::query()->create([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_SUCCEEDED,
            'finished_at' => now(),
        ]);
        ActorExecution::query()->create([
            'actor_name' => 'reindex',
            'status' => ActorExecution::STATUS_SUCCEEDED,
        ]);
        WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document_added',
            'status' => WebhookDelivery::STATUS_FAILED,
            'dedupe_key' => 'stats-webhook-failed',
            'payload_hash' => hash('sha256', 'stats-webhook-failed'),
            'raw_payload' => [],
            'received_at' => now(),
            'error' => 'webhook failed',
        ]);

        $this->actingAs($user)
            ->get('/stats')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('stats/Index')
                ->where('review.pending', 1)
                ->where('dailyActivity.6.commands_finished', 1)
                ->missing('workerStatusCounts')
                ->missing('workerTypeMatrix')
            );
    }

    public function test_errors_page_reports_webhook_and_legacy_errors_with_durable_operations(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document_added',
            'status' => WebhookDelivery::STATUS_FAILED,
            'dedupe_key' => 'errors-webhook-failed',
            'payload_hash' => hash('sha256', 'errors-webhook-failed'),
            'raw_payload' => [],
            'received_at' => now(),
            'error' => 'delivery failed',
        ]);

        $this->actingAs($user)
            ->get('/errors')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('diagnostics/Errors')
                ->where('filterOptions.sources', ['all', 'webhook', 'legacy'])
                ->where('webhookErrors.data.0.error', 'delivery failed')
            );
    }
}
