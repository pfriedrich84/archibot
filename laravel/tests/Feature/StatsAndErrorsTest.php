<?php

namespace Tests\Feature;

use App\Models\ActorExecution;
use App\Models\Command;
use App\Models\EntityApproval;
use App\Models\PipelineRun;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\LegacyPythonState;
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

    public function test_stats_endpoint_preserves_canonical_diagnostics_and_never_emits_adversarial_dynamic_keys(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        ReviewSuggestion::factory()->create(['status' => 'STATUS_STATS_SECRET', 'judge_verdict' => 'VERDICT_STATS_SECRET']);
        ReviewSuggestion::factory()->create(['status' => ReviewSuggestion::STATUS_PENDING, 'judge_verdict' => 'agree']);
        EntityApproval::query()->create(['type' => 'TYPE_STATS_SECRET', 'name' => 'test', 'status' => 'ENTITY_STATUS_SECRET']);
        WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document.updated',
            'dedupe_key' => 'stats-adversarial',
            'payload_hash' => hash('sha256', 'stats-adversarial'),
            'status' => 'WEBHOOK_STATUS_STATS_SECRET',
            'received_at' => now(),
        ]);
        PipelineRun::query()->create([
            'type' => 'PIPELINE_TYPE_STATS_SECRET',
            'status' => 'PIPELINE_STATUS_STATS_SECRET',
            'scope' => 'test',
            'trigger_source' => 'manual',
        ]);
        ActorExecution::query()->create(['actor_name' => 'ACTOR_STATS_SECRET', 'status' => 'ACTOR_STATUS_STATS_SECRET']);
        ActorExecution::query()->create(['actor_name' => 'build_initial_embedding_index', 'status' => ActorExecution::STATUS_RUNNING]);

        $this->mock(LegacyPythonState::class, function ($mock): void {
            $mock->shouldReceive('stats')->once()->andReturn([
                'available' => true,
                'totals' => ['processed_documents' => 5],
                'status_counts' => ['committed' => 2, 'LEGACY_STATUS_SECRET' => 1],
                'judge_counts' => ['corrected' => 3, 'LEGACY_VERDICT_SECRET' => 1],
                'confidence_distribution' => ['80-100' => 2, 'LEGACY_BUCKET_SECRET' => 1],
                'phase_health' => [
                    'review_commit_paperless' => ['total' => 2, 'errors' => 0, 'avg_ms' => 15, 'error_rate_pct' => 0],
                    'LEGACY_PHASE_SECRET' => ['total' => 1, 'errors' => 1, 'avg_ms' => 2, 'error_rate_pct' => 100],
                ],
            ]);
        });

        $response = $this->actingAs($admin)->get(route('stats.index'));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('reviewStatusCounts.pending', 1)
            ->where('reviewStatusCounts.unknown', 1)
            ->where('reviewJudgeCounts.agree', 1)
            ->where('entityApprovalMatrix.unknown.unknown', 1)
            ->where('webhookStatusCounts.unknown', 1)
            ->where('pipelineRunStatusCounts.unknown', 1)
            ->where('pipelineRunTypeMatrix.unknown.unknown', 1)
            ->where('actorStatusCounts.unknown', 1)
            ->where('actorNameMatrix.build_initial_embedding_index.running', 1)
            ->where('actorNameMatrix.unknown.unknown', 1)
            ->where('python.status_counts.committed', 2)
            ->where('python.judge_counts.corrected', 3)
            ->where('python.phase_health.review_commit_paperless.total', 2)
            ->where('python.phase_health.unknown.total', 1)
        );

        foreach (['STATUS_STATS_SECRET', 'VERDICT_STATS_SECRET', 'TYPE_STATS_SECRET',
            'WEBHOOK_STATUS_STATS_SECRET', 'PIPELINE_TYPE_STATS_SECRET', 'ACTOR_STATS_SECRET', 'LEGACY_STATUS_SECRET',
            'LEGACY_VERDICT_SECRET', 'LEGACY_BUCKET_SECRET', 'LEGACY_PHASE_SECRET'] as $secret) {
            $response->assertDontSee($secret, escaped: false);
        }
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
                ->where('webhookErrors.data.0.error', 'Details redacted. Use the status, error type, identifiers and timeline to diagnose or recover this operation.')
            );
    }
}
