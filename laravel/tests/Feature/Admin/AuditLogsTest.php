<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\ReviewSuggestion;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WorkerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_view_audit_logs(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'paperless_username' => 'admin']);
        $user = User::factory()->create(['is_admin' => false]);

        AuditLog::query()->create([
            'actor_user_id' => $admin->id,
            'event' => 'setup.completed',
            'target_type' => 'setup',
            'metadata' => ['paperless_url' => 'https://paperless.test'],
        ]);

        $this->actingAs($user)->get(route('admin.audit-logs.index'))->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/AuditLogs')
                ->has('logs', 1)
                ->where('logs.0.event', 'setup.completed')
                ->where('logs.0.actor.paperless_username', 'admin')
            );
    }

    public function test_audit_logs_include_operator_target_links_for_known_surfaces(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $workerJob = WorkerJob::factory()->create();
        $webhookDelivery = WebhookDelivery::query()->create([
            'source' => 'paperless',
            'event_type' => 'document.updated',
            'paperless_document_id' => 42,
            'dedupe_key' => 'dedupe-1',
            'payload_hash' => str_repeat('b', 64),
            'raw_payload' => ['document_id' => 42],
            'status' => WebhookDelivery::STATUS_FAILED,
            'received_at' => now(),
        ]);
        $reviewSuggestion = ReviewSuggestion::factory()->create();

        $workerLog = AuditLog::query()->create([
            'event' => 'worker_job.queued',
            'target_type' => 'worker_job',
            'target_id' => (string) $workerJob->id,
        ]);
        $webhookLog = AuditLog::query()->create([
            'event' => 'webhook_delivery.retry_queued',
            'target_type' => 'webhook_delivery',
            'target_id' => (string) $webhookDelivery->id,
        ]);
        $reviewLog = AuditLog::query()->create([
            'event' => 'review_suggestion.accepted',
            'target_type' => 'review_suggestion',
            'target_id' => (string) $reviewSuggestion->id,
        ]);

        $workerLog->forceFill(['created_at' => now()->subMinutes(2)])->save();
        $webhookLog->forceFill(['created_at' => now()->subMinute()])->save();
        $reviewLog->forceFill(['created_at' => now()])->save();

        $this->actingAs($admin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/AuditLogs')
                ->has('logs', 3)
                ->where('logs.0.target_url', route('review.show', $reviewSuggestion))
                ->where('logs.1.target_url', route('webhook-deliveries.show', $webhookDelivery))
                ->where('logs.2.target_url', route('worker-jobs.show', $workerJob))
            );
    }
}
