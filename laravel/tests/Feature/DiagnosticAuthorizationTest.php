<?php

namespace Tests\Feature;

use App\Models\ActorExecution;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class DiagnosticAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_is_forbidden_from_every_diagnostic_route_before_model_binding(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_FAILED,
            'scope' => 'document:42',
            'trigger_source' => 'manual',
            'paperless_document_id' => 42,
        ]);
        $delivery = WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document.updated',
            'dedupe_key' => 'diagnostic-auth-test',
            'payload_hash' => hash('sha256', 'diagnostic-auth-test'),
            'raw_payload' => ['document_id' => 42],
            'status' => WebhookDelivery::STATUS_FAILED,
            'received_at' => now(),
        ]);

        $getRoutes = [
            route('stats.index'),
            route('errors.index'),
            route('operations-log.index'),
            route('pipeline-runs.index'),
            route('pipeline-runs.show', $run),
            route('pipeline-runs.show', 999999),
            route('webhook-deliveries.index'),
            route('webhook-deliveries.show', $delivery),
            route('webhook-deliveries.show', 999999),
            route('embeddings.index'),
            route('admin.audit-logs.index'),
            route('admin.maintenance.index'),
        ];

        foreach ($getRoutes as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }

        $postRoutes = [
            route('pipeline-runs.retry', $run),
            route('pipeline-runs.retry', 999999),
            route('pipeline-runs.retry-failed-items', $run),
            route('pipeline-runs.retry-failed-items', 999999),
            route('pipeline-runs.cancel', $run),
            route('pipeline-runs.cancel', 999999),
            route('webhook-deliveries.retry', $delivery),
            route('webhook-deliveries.retry', 999999),
            route('webhook-deliveries.dismiss', $delivery),
            route('webhook-deliveries.dismiss', 999999),
            route('embedding-index.build'),
            route('embedding-index.mark-stale'),
            route('maintenance.poll'),
            route('maintenance.reindex'),
            route('admin.maintenance.recover-pipeline-actors'),
            route('admin.maintenance.document-pipeline'),
            route('admin.maintenance.commands'),
        ];

        foreach ($postRoutes as $url) {
            $this->actingAs($user)->post($url)->assertForbidden();
        }

        $this->assertSame(PipelineRun::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame(WebhookDelivery::STATUS_FAILED, $delivery->fresh()->status);
        $this->assertDatabaseCount('commands', 0);
    }

    public function test_admin_can_open_every_diagnostic_route_group(): void
    {
        Http::fake(['*' => Http::response(['count' => 0, 'results' => []])]);
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'test-token']);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_FAILED,
            'scope' => 'document:42',
            'trigger_source' => 'manual',
            'paperless_document_id' => 42,
        ]);
        $delivery = WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document.updated',
            'dedupe_key' => 'diagnostic-admin-test',
            'payload_hash' => hash('sha256', 'diagnostic-admin-test'),
            'raw_payload' => ['document_id' => 42],
            'status' => WebhookDelivery::STATUS_FAILED,
            'received_at' => now(),
        ]);

        foreach ([
            route('stats.index'),
            route('errors.index'),
            route('operations-log.index'),
            route('pipeline-runs.index'),
            route('pipeline-runs.show', $run),
            route('webhook-deliveries.index'),
            route('webhook-deliveries.show', $delivery),
            route('embeddings.index'),
            route('admin.audit-logs.index'),
            route('admin.maintenance.index'),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_non_admin_dashboard_omits_all_global_operational_diagnostics(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        AppSetting::put('llm.provider', 'token_secret_123');
        Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_RUNNING,
        ]);
        PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_RUNNING,
            'scope' => 'document:42',
            'trigger_source' => 'manual',
            'paperless_document_id' => 42,
        ]);
        ActorExecution::query()->create([
            'actor_name' => 'secret-actor-name',
            'status' => ActorExecution::STATUS_RUNNING,
        ]);
        WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document.updated',
            'dedupe_key' => 'secret-dashboard-delivery',
            'payload_hash' => hash('sha256', 'secret-dashboard-delivery'),
            'raw_payload' => ['document_id' => 42],
            'status' => WebhookDelivery::STATUS_QUEUED,
            'received_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where(
                'status.llm_provider',
                'Llm Provider (ref:'.substr(hash('sha256', 'token_secret_123'), 0, 12).')',
            )
            ->where('counts.pending_reviews', 0)
            ->missing('counts.queued_webhook_deliveries')
            ->missing('counts.active_pipeline_runs')
            ->missing('counts.running_actor_executions')
            ->missing('embeddingIndex')
            ->missing('maintenance')
            ->missing('activeOperations')
            ->missing('recentWebhookDeliveries')
            ->missing('recentActorExecutions')
            ->missing('recentPipelineRuns')
            ->missing('recentErrors')
        );
        $response->assertDontSee('token_secret_123', escape: false);
        $response->assertDontSee('secret-actor-name', escape: false);
        $response->assertDontSee('secret-dashboard-delivery', escape: false);
    }

    public function test_dashboard_replaces_paperless_exception_text_with_fixed_safe_text(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
            'paperless_token' => 'test-token',
        ]);
        $this->mock(PaperlessClient::class, function ($mock): void {
            $mock->shouldReceive('ping')
                ->once()
                ->andThrow(new RuntimeException('Bearer top-secret private document title'));
        });

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('status.paperless_available', false)
            ->where('status.paperless_error', 'Paperless server is not reachable.')
        );
        $response->assertDontSee('top-secret', escape: false);
        $response->assertDontSee('private document title', escape: false);
    }

    public function test_allowed_metadata_keys_and_webhook_summary_cannot_carry_sensitive_strings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        AuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'event' => 'diagnostic.adversarial',
            'metadata' => [
                'status' => 'Bearer top-secret',
                'phase' => 'private OCR content',
                'pipeline_run_id' => 42,
            ],
        ]);
        $delivery = WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document.updated',
            'dedupe_key' => 'adversarial-webhook',
            'payload_hash' => hash('sha256', 'adversarial-webhook'),
            'normalized_payload' => [
                'document_id' => 42,
                'event' => 'Bearer webhook-secret',
                'action' => 'private document content',
            ],
            'raw_payload' => [],
            'status' => WebhookDelivery::STATUS_FAILED,
            'received_at' => now(),
        ]);

        $auditResponse = $this->actingAs($admin)->get(route('admin.audit-logs.index'));
        $auditResponse->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('logs.0.metadata', 1)
            ->where('logs.0.metadata.0.key', 'pipeline_run_id')
            ->where('logs.0.metadata.0.value', 42)
        );

        $webhookResponse = $this->actingAs($admin)->get(route('webhook-deliveries.show', $delivery));
        $webhookResponse->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('delivery.payload_summary', 1)
            ->where('delivery.payload_summary.0.key', 'document_id')
            ->where('delivery.payload_summary.0.value', 42)
        );

        foreach ([$auditResponse, $webhookResponse] as $response) {
            $response->assertDontSee('top-secret', escape: false);
            $response->assertDontSee('webhook-secret', escape: false);
            $response->assertDontSee('private OCR content', escape: false);
            $response->assertDontSee('private document content', escape: false);
        }
    }

    public function test_admin_diagnostic_endpoints_never_echo_attacker_controlled_top_level_scalars(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $maliciousEvent = 'document.updated<script>EVENT_SECRET';
        $maliciousModified = '2026-01-01T00:00:00Z:MODIFIED_SECRET';
        $maliciousDedupe = "paperless:{$maliciousEvent}:42:{$maliciousModified}";
        $maliciousRequestId = 'REQUEST_SECRET</script>';
        $maliciousPipelineEvent = 'pipeline.failed.PIPELINE_EVENT_SECRET';
        $maliciousActor = 'handle_document_pipeline.ACTOR_SECRET';
        $maliciousErrorType = 'AuthorizationTokenSecretError';
        $maliciousAuditEvent = 'pipeline_run.retry_queued.AUDIT_SECRET';
        EmbeddingIndexState::query()->create([
            'status' => EmbeddingIndexState::STATUS_COMPLETE,
            'embedding_model' => 'sk-prod-secret123',
            'document_count' => 0,
            'embedded_count' => 0,
            'failed_count' => 0,
        ]);
        $command = Command::query()->create([
            'type' => 'poll_reconciliation.COMMAND_TYPE_SECRET',
            'status' => Command::STATUS_RUNNING,
            'payload' => [
                'limit' => 'COMMAND_PAYLOAD_SECRET',
                'error_type' => $maliciousErrorType,
            ],
        ]);

        $delivery = WebhookDelivery::query()->create([
            'source' => 'paperless.SOURCE_SECRET',
            'event_type' => $maliciousEvent,
            'paperless_document_id' => 42,
            'dedupe_key' => $maliciousDedupe,
            'payload_hash' => hash('sha256', 'adversarial-payload'),
            'normalized_payload' => [
                'document_id' => 42,
                'event_type' => $maliciousEvent,
                'paperless_modified' => $maliciousModified,
            ],
            'raw_payload' => [],
            'request_id' => $maliciousRequestId,
            'status' => WebhookDelivery::STATUS_FAILED,
            'received_at' => now(),
        ]);
        $run = PipelineRun::query()->create([
            'command_id' => $command->id,
            'webhook_delivery_id' => $delivery->id,
            'type' => 'document',
            'status' => PipelineRun::STATUS_FAILED,
            'scope' => 'document:42:SCOPE_SECRET',
            'trigger_source' => 'webhook.TRIGGER_SECRET',
            'paperless_document_id' => 42,
            'progress_current_phase' => 'classification.PHASE_SECRET',
            'retry_mode' => 'manual.RETRY_MODE_SECRET',
            'error_type' => $maliciousErrorType,
        ]);
        PipelineEvent::query()->create([
            'pipeline_run_id' => $run->id,
            'webhook_delivery_id' => $delivery->id,
            'event_type' => $maliciousPipelineEvent,
            'level' => 'error<script>LEVEL_SECRET',
            'paperless_document_id' => 42,
        ]);
        ActorExecution::query()->create([
            'pipeline_run_id' => $run->id,
            'actor_name' => $maliciousActor,
            'queue_name' => 'pipeline.QUEUE_SECRET',
            'status' => ActorExecution::STATUS_FAILED,
            'error_type' => $maliciousErrorType,
            'worker_id' => 'WORKER_SECRET',
        ]);
        AuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'event' => $maliciousAuditEvent,
            'target_type' => 'pipeline_run',
            'target_id' => (string) $run->id,
            'metadata' => ['error_type' => $maliciousErrorType],
        ]);
        AuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'event' => 'maintenance.reindex_requested.MAINTENANCE_EVENT_SECRET',
            'target_type' => 'command',
            'target_id' => (string) $command->id,
            'metadata' => ['command_type' => 'MAINTENANCE_METADATA_SECRET'],
        ]);

        $webhookShow = $this->actingAs($admin)->get(route('webhook-deliveries.show', $delivery));
        $webhookShow->assertInertia(fn (Assert $page) => $page
            ->where('delivery.event_type', 'Event Type (ref:'.substr(hash('sha256', $maliciousEvent), 0, 12).')')
            ->where('delivery.dedupe_key', 'ref:'.substr(hash('sha256', $maliciousDedupe), 0, 12))
            ->where('delivery.request_id', 'ref:'.substr(hash('sha256', $maliciousRequestId), 0, 12))
            ->where('delivery.pipeline_events.0.event_type', 'Event Type (ref:'.substr(hash('sha256', $maliciousPipelineEvent), 0, 12).')')
            ->where('delivery.pipeline_events.0.level', 'Level (ref:'.substr(hash('sha256', 'error<script>LEVEL_SECRET'), 0, 12).')')
        );

        $responses = [
            $this->actingAs($admin)->get(route('webhook-deliveries.index')),
            $webhookShow,
            $this->actingAs($admin)->get(route('errors.index')),
            $this->actingAs($admin)->get(route('pipeline-runs.index')),
            $this->actingAs($admin)->get(route('pipeline-runs.show', $run)),
            $this->actingAs($admin)->get(route('operations-log.index')),
            $this->actingAs($admin)->get(route('dashboard')),
            $this->actingAs($admin)->get(route('admin.audit-logs.index')),
            $this->actingAs($admin)->get(route('stats.index')),
            $this->actingAs($admin)->get(route('embeddings.index')),
            $this->actingAs($admin)->get(route('admin.maintenance.index')),
        ];

        foreach ($responses as $response) {
            $response->assertOk();
            foreach ([
                'EVENT_SECRET', 'MODIFIED_SECRET', 'REQUEST_SECRET', 'PIPELINE_EVENT_SECRET',
                'ACTOR_SECRET', 'AuthorizationTokenSecretError', 'AUDIT_SECRET', 'LEVEL_SECRET',
                'QUEUE_SECRET', 'WORKER_SECRET', 'SOURCE_SECRET', 'SCOPE_SECRET', 'TRIGGER_SECRET',
                'PHASE_SECRET', 'RETRY_MODE_SECRET', 'COMMAND_TYPE_SECRET', 'COMMAND_PAYLOAD_SECRET',
                'MAINTENANCE_EVENT_SECRET', 'MAINTENANCE_METADATA_SECRET', 'sk-prod-secret123',
            ] as $forbidden) {
                $response->assertDontSee($forbidden, escape: false);
            }
        }

        $this->actingAs($admin)
            ->get(route('errors.index', ['status' => 'failed<script>FILTER_SECRET']))
            ->assertSessionHasErrors('status');
    }

    public function test_canonical_recovery_identifiers_survive_the_endpoint_boundary(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $run = PipelineRun::query()->create([
            'type' => 'document',
            'status' => PipelineRun::STATUS_RUNNING,
            'scope' => 'document:42',
            'trigger_source' => 'webhook',
            'paperless_document_id' => 42,
            'progress_current_phase' => 'review_commit_paperless',
        ]);
        PipelineEvent::query()->create([
            'pipeline_run_id' => $run->id,
            'event_type' => 'poll.reconciliation.completed',
            'level' => 'info',
        ]);
        ActorExecution::query()->create([
            'pipeline_run_id' => $run->id,
            'actor_name' => 'reindex_ocr',
            'queue_name' => 'laravel.database',
            'status' => ActorExecution::STATUS_RETRYING,
            'error_type' => 'transient_network',
        ]);
        AuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'event' => 'scheduler.poll_reconciliation_enqueue_failed',
            'target_type' => 'pipeline_run',
            'target_id' => (string) $run->id,
            'metadata' => ['retry_class' => 'transient_network'],
        ]);

        $this->actingAs($admin)->get(route('operations-log.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pipelineRuns.0.progress_current_phase', 'review_commit_paperless')
                ->where('pipelineEvents.0.event_type', 'poll.reconciliation.completed')
                ->where('actorExecutions.0.actor_name', 'reindex_ocr')
                ->where('actorExecutions.0.error_type', 'transient_network')
                ->where('auditLogs.0.event', 'scheduler.poll_reconciliation_enqueue_failed')
            );

        $this->actingAs($admin)->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.0.event', 'scheduler.poll_reconciliation_enqueue_failed')
                ->where('logs.0.metadata.0.key', 'retry_class')
                ->where('logs.0.metadata.0.value', 'transient_network')
            );
    }

    public function test_diagnostic_frontend_has_no_raw_json_presentation(): void
    {
        $paths = [
            resource_path('js/pages/Dashboard.svelte'),
            resource_path('js/pages/operations-log/Index.svelte'),
            resource_path('js/pages/pipeline-runs/Index.svelte'),
            resource_path('js/pages/pipeline-runs/Show.svelte'),
            resource_path('js/pages/webhooks/Index.svelte'),
            resource_path('js/pages/webhooks/Show.svelte'),
            resource_path('js/pages/diagnostics/Errors.svelte'),
            resource_path('js/pages/stats/Index.svelte'),
            resource_path('js/pages/processing/Embeddings.svelte'),
            resource_path('js/pages/admin/AuditLogs.svelte'),
            resource_path('js/pages/admin/Maintenance.svelte'),
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('JSON.stringify', $source, $path);
            $this->assertStringNotContainsString('<pre', $source, $path);
        }

        foreach ([
            resource_path('js/pages/webhooks/Index.svelte'),
            resource_path('js/pages/webhooks/Show.svelte'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('raw_payload', $source, $path);
            $this->assertStringNotContainsString('normalized_payload', $source, $path);
            $this->assertStringNotContainsString('header_summary', $source, $path);
        }
    }
}
