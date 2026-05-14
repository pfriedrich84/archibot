<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\EntityApproval;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WorkerJob;
use App\Services\LegacyPythonState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Tests\TestCase;

class StatsAndErrorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_view_restored_stats_page(): void
    {
        $user = User::factory()->create();
        ReviewSuggestion::factory()->create(['status' => ReviewSuggestion::STATUS_PENDING]);
        EntityApproval::factory()->create(['status' => EntityApproval::STATUS_APPROVED]);
        WorkerJob::factory()->create(['status' => WorkerJob::STATUS_FAILED]);
        ChatSession::query()->create([
            'id' => 'session123456789',
            'user_id' => $user->id,
            'origin' => 'web',
            'title' => 'Question',
            'last_active_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('stats.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('stats/Index')
                ->where('review.pending', 1)
                ->where('entities.approved', 1)
                ->where('workers.failed', 1)
                ->where('chat.sessions', 1)
                ->has('python.available')
            );
    }

    public function test_stats_page_includes_legacy_python_classifier_metrics_when_available(): void
    {
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'archibot-python-db-');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE processed_documents (document_id INTEGER)');
        $pdo->exec('CREATE TABLE doc_embedding_meta (document_id INTEGER)');
        $pdo->exec('CREATE TABLE errors (id INTEGER PRIMARY KEY, occurred_at TEXT, stage TEXT, document_id INTEGER, message TEXT, details TEXT)');
        $pdo->exec('CREATE TABLE audit_log (action TEXT, actor TEXT)');
        $pdo->exec('CREATE TABLE suggestions (status TEXT, confidence INTEGER, judge_verdict TEXT)');
        $pdo->exec('CREATE TABLE phase_timing (phase TEXT, success INTEGER, duration_ms INTEGER)');
        $pdo->exec('INSERT INTO processed_documents VALUES (1)');
        $pdo->exec('INSERT INTO doc_embedding_meta VALUES (1)');
        $pdo->exec("INSERT INTO errors(stage, message) VALUES ('classify', 'Failed')");
        $pdo->exec("INSERT INTO audit_log VALUES ('commit', 'auto')");
        $pdo->exec("INSERT INTO suggestions VALUES ('pending', 87, 'accepted')");
        $pdo->exec("INSERT INTO phase_timing VALUES ('classify', 0, 42)");
        $this->app->instance(LegacyPythonState::class, new LegacyPythonState($path));

        $this->actingAs($user)
            ->get(route('stats.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('stats/Index')
                ->where('python.available', true)
                ->where('python.totals.processed_documents', 1)
                ->where('python.totals.embedded_documents', 1)
                ->where('python.totals.total_errors', 1)
                ->where('python.status_counts.pending', 1)
                ->where('python.judge_counts.accepted', 1)
                ->where('python.phase_health.classify.errors', 1)
            );

        @unlink($path);
    }

    public function test_authenticated_users_can_view_restored_errors_page(): void
    {
        $user = User::factory()->create();
        $failed = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_REINDEX,
            'status' => WorkerJob::STATUS_FAILED,
            'error' => 'Classifier failed',
            'progress' => ['phase' => 'embedding'],
        ]);
        WorkerJob::factory()->create(['status' => WorkerJob::STATUS_SUCCEEDED]);

        $this->actingAs($user)
            ->get(route('errors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('diagnostics/Errors')
                ->where('isAdmin', false)
                ->has('failedJobs.data', 1)
                ->where('failedJobs.data.0.id', $failed->id)
                ->where('failedJobs.data.0.error', 'Classifier failed')
                ->where('failedJobs.data.0.show_url', route('worker-jobs.show', $failed))
                ->where('failedJobs.data.0.can_retry', false)
                ->has('webhookErrors.data', 0)
                ->has('legacyErrors', 0)
            );
    }

    public function test_errors_page_includes_legacy_python_classifier_errors_when_available(): void
    {
        $user = User::factory()->create();
        $path = tempnam(sys_get_temp_dir(), 'archibot-python-db-');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE errors (id INTEGER PRIMARY KEY, occurred_at TEXT, stage TEXT, document_id INTEGER, message TEXT, details TEXT)');
        $pdo->exec("INSERT INTO errors(occurred_at, stage, document_id, message, details) VALUES ('2026-05-08T10:00:00Z', 'classify', 123, 'Classifier failed', '{\"reason\":\"timeout\"}')");
        $this->app->instance(LegacyPythonState::class, new LegacyPythonState($path));

        $this->actingAs($user)
            ->get(route('errors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('diagnostics/Errors')
                ->has('legacyErrors', 1)
                ->where('legacyErrors.0.stage', 'classify')
                ->where('legacyErrors.0.document_reference', 123)
                ->where('legacyErrors.0.details.reason', 'timeout')
            );

        @unlink($path);
    }

    public function test_errors_page_includes_webhook_delivery_errors_with_admin_actions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $delivery = WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document.updated',
            'paperless_document_id' => 42,
            'dedupe_key' => 'dedupe-42',
            'payload_hash' => str_repeat('c', 64),
            'raw_payload' => ['document_id' => 42],
            'normalized_payload' => ['document_id' => 42, 'event' => 'updated'],
            'status' => WebhookDelivery::STATUS_BLOCKED,
            'request_id' => 'req-42',
            'received_at' => now(),
            'error' => 'Embedding index is rebuilding',
        ]);

        $this->actingAs($admin)
            ->get(route('errors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('diagnostics/Errors')
                ->where('isAdmin', true)
                ->has('webhookErrors.data', 1)
                ->where('webhookErrors.data.0.id', $delivery->id)
                ->where('webhookErrors.data.0.status', WebhookDelivery::STATUS_BLOCKED)
                ->where('webhookErrors.data.0.error', 'Embedding index is rebuilding')
                ->where('webhookErrors.data.0.show_url', route('webhook-deliveries.show', $delivery))
                ->where('webhookErrors.data.0.retry_url', route('webhook-deliveries.retry', $delivery))
                ->where('webhookErrors.data.0.dismiss_url', route('webhook-deliveries.dismiss', $delivery))
                ->where('webhookErrors.data.0.can_retry', true)
                ->where('webhookErrors.data.0.can_dismiss', true)
            );
    }

    public function test_errors_page_filters_by_source_and_status(): void
    {
        $user = User::factory()->create();
        WorkerJob::factory()->create(['status' => WorkerJob::STATUS_FAILED]);
        WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document.updated',
            'paperless_document_id' => 42,
            'dedupe_key' => 'dedupe-filter',
            'payload_hash' => str_repeat('d', 64),
            'raw_payload' => ['document_id' => 42],
            'status' => WebhookDelivery::STATUS_FAILED_PERMANENT,
            'received_at' => now(),
            'error' => 'Invalid payload',
        ]);

        $this->actingAs($user)
            ->get(route('errors.index', ['source' => 'webhook', 'status' => WebhookDelivery::STATUS_FAILED_PERMANENT]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('diagnostics/Errors')
                ->where('filters.source', 'webhook')
                ->where('filters.status', WebhookDelivery::STATUS_FAILED_PERMANENT)
                ->has('failedJobs.data', 0)
                ->has('webhookErrors.data', 1)
                ->where('webhookErrors.data.0.status', WebhookDelivery::STATUS_FAILED_PERMANENT)
                ->has('legacyErrors', 0)
            );
    }

    public function test_non_admin_errors_page_does_not_expose_mutating_action_urls(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $job = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_FAILED,
            'result' => ['failed_document_ids' => [123]],
        ]);
        $delivery = WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document.updated',
            'paperless_document_id' => 42,
            'dedupe_key' => 'dedupe-non-admin',
            'payload_hash' => str_repeat('e', 64),
            'raw_payload' => ['document_id' => 42],
            'status' => WebhookDelivery::STATUS_FAILED,
            'received_at' => now(),
            'error' => 'Queue unavailable',
        ]);

        $this->actingAs($user)
            ->get(route('errors.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('diagnostics/Errors')
                ->where('failedJobs.data.0.id', $job->id)
                ->where('failedJobs.data.0.retry_url', null)
                ->where('failedJobs.data.0.can_retry', false)
                ->where('failedJobs.data.0.can_retry_failed_only', false)
                ->where('webhookErrors.data.0.id', $delivery->id)
                ->where('webhookErrors.data.0.retry_url', null)
                ->where('webhookErrors.data.0.dismiss_url', null)
                ->where('webhookErrors.data.0.can_retry', false)
                ->where('webhookErrors.data.0.can_dismiss', false)
            );
    }
}
