<?php

namespace Tests\Feature;

use App\Jobs\RunPythonActorJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_queue_uses_application_connection_and_lease_exceeds_actor_timeout(): void
    {
        $job = RunPythonActorJob::reindex(1);

        $this->assertSame('database', config('queue.default'));
        $this->assertSame(config('database.default'), config('queue.connections.database.connection'));
        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
    }

    public function test_actor_timeout_uses_operator_configuration_in_queue_payload(): void
    {
        config(['archibot_workers.queue_worker_timeout' => 1234]);
        $job = RunPythonActorJob::reindex(1);

        dispatch($job);
        $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1234, $job->timeout);
        $this->assertSame(1234, $payload['timeout']);
    }
}
