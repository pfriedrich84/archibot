<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthz_returns_ok_when_database_is_reachable_and_recovery_is_recent(): void
    {
        AppSetting::put('worker_jobs.recovery.last_successful_at', now()->toISOString());

        $response = $this->get('/healthz');

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'checks' => [
                    'database' => 'ok',
                    'worker_recovery' => 'ok',
                    'paperless_config' => 'ok',
                ],
            ])
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'database',
                    'worker_recovery',
                    'queue',
                    'paperless_config',
                    'python_runtime',
                ],
            ]);
    }

    public function test_healthz_returns_degraded_when_recovery_is_stale(): void
    {
        AppSetting::put('worker_jobs.recovery.last_successful_at', now()->subMinutes(10)->toISOString());

        $response = $this->get('/healthz');

        $response->assertOk()
            ->assertJson([
                'status' => 'degraded',
                'checks' => [
                    'worker_recovery' => 'stale',
                ],
            ]);
    }

    public function test_healthz_returns_degraded_when_recovery_is_unknown(): void
    {
        $response = $this->get('/healthz');

        $response->assertOk()
            ->assertJson([
                'status' => 'degraded',
                'checks' => [
                    'worker_recovery' => 'unknown',
                ],
            ]);
    }

    public function test_healthz_returns_missing_for_paperless_config_when_not_configured(): void
    {
        AppSetting::query()->where('key', 'paperless.url')->delete();
        AppSetting::put('worker_jobs.recovery.last_successful_at', now()->toISOString());

        $response = $this->get('/healthz');

        $response->assertOk()
            ->assertJson([
                'status' => 'degraded',
                'checks' => [
                    'paperless_config' => 'missing',
                ],
            ]);
    }
}
