<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_prune_removes_old_audit_logs_only(): void
    {
        $oldLog = AuditLog::query()->create([
            'event' => 'old.event',
            'target_type' => 'test',
        ]);
        $oldLog->forceFill(['created_at' => now()->subDays(10)])->save();

        AuditLog::query()->create([
            'event' => 'new.event',
            'target_type' => 'test',
        ]);

        $this->artisan('archibot:audit-prune', ['--days' => 7])
            ->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $oldLog->id]);
        $this->assertDatabaseCount('audit_logs', 1);
    }
}
