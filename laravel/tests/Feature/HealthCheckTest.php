<?php

namespace Tests\Feature;

use App\Models\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthz_reports_ok_without_retired_checks(): void
    {
        $this->getJson('/healthz')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonMissingPath('checks.worker_recovery')
            ->assertJsonMissingPath('checks.retired_queue');
    }

    public function test_healthz_warns_for_pending_commands(): void
    {
        Command::query()->create([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_QUEUED,
            'created_at' => now()->subHours(3),
        ]);

        $this->getJson('/healthz')
            ->assertOk()
            ->assertJsonMissingPath('checks.retired_queue');
    }
}
