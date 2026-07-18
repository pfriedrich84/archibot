<?php

namespace Tests\Integration;

use App\Models\Command;
use App\Models\EntityApproval;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** @group postgres */
class PostgresEntityDecisionConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires the real PostgreSQL integration test service.');
        }
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
    }

    public function test_true_concurrent_conflicting_requests_create_exactly_one_active_decision(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'token']);
        $entity = EntityApproval::factory()->create(['type' => EntityApproval::TYPE_TAG]);
        $startAt = sprintf('%.6f', microtime(true) + 0.75);
        $fixture = base_path('tests/Fixtures/concurrent_entity_decision.php');
        $environment = $this->processEnvironment();

        $approve = new Process([PHP_BINARY, $fixture, (string) $entity->id, (string) $admin->id, 'approved', $startAt], base_path(), $environment);
        $reject = new Process([PHP_BINARY, $fixture, (string) $entity->id, (string) $admin->id, 'rejected', $startAt], base_path(), $environment);
        $approve->start();
        $reject->start();
        $approve->wait();
        $reject->wait();

        $this->assertTrue($approve->isSuccessful(), $approve->getErrorOutput());
        $this->assertTrue($reject->isSuccessful(), $reject->getErrorOutput());
        $outputs = [$approve->getOutput(), $reject->getOutput()];
        $this->assertCount(1, array_filter($outputs, fn (string $output) => str_contains($output, 'OK:')));
        $this->assertCount(1, array_filter($outputs, fn (string $output) => str_contains($output, 'CONFLICT:')));
        $this->assertDatabaseCount('commands', 1);

        $command = Command::query()->firstOrFail();
        $entity->refresh();
        $this->assertSame($command->id, $entity->active_decision_command_id);
        $this->assertSame($command->payload['decision_token'], $entity->active_decision_token);
        $this->assertSame($command->payload['decision_version'], $entity->decision_version);
        $this->assertSame($command->payload['action'], $entity->active_decision_action);
    }

    /** @return array<string, string> */
    private function processEnvironment(): array
    {
        $database = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing',
            'QUEUE_CONNECTION' => 'database',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) $database['host'],
            'DB_PORT' => (string) $database['port'],
            'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'],
            'DB_PASSWORD' => (string) $database['password'],
        ];
    }
}
