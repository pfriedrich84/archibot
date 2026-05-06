<?php

namespace Tests\Feature\Admin;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\WorkerJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_audit_logs_older_than_configured_retention(): void
    {
        AppSetting::put('audit.retention_days', '7');

        $old = AuditLog::query()->create(['event' => 'old.event']);
        $old->forceFill(['created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)])->save();

        AuditLog::query()->create(['event' => 'recent.event']);

        $oldJob = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_SUCCEEDED,
            'finished_at' => now()->subDays(8),
        ]);
        $oldJob->logs()->create(['message' => 'old log line']);
        WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_RUNNING,
            'finished_at' => now()->subDays(8),
        ]);

        $this->artisan('archibot:audit-prune')
            ->expectsOutput('Pruned 1 audit log entries and 1 completed worker jobs older than 7 days.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['event' => 'old.event']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'recent.event']);
        $this->assertDatabaseMissing('worker_jobs', ['id' => $oldJob->id]);
        $this->assertDatabaseMissing('worker_job_logs', ['message' => 'old log line']);
        $this->assertDatabaseCount('worker_jobs', 1);
    }
}
