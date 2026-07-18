<?php

namespace Tests\Feature;

use App\Jobs\RunPythonActorJob;
use App\Models\Command;
use App\Models\PipelineEvent;
use App\Services\Actors\PythonActorRunner;
use App\Services\Pipeline\MaintenanceCommandDispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduledPollReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_poll_creates_and_dispatches_durable_command(): void
    {
        Queue::fake();
        config(['archibot.poll_interval_seconds' => 600]);

        $this->artisan('archibot:scheduled-poll')
            ->expectsOutputToContain('Scheduled poll reconciliation command')
            ->assertSuccessful();

        $command = Command::query()->firstOrFail();
        $this->assertSame(Command::TYPE_POLL_RECONCILIATION, $command->type);
        $this->assertSame(Command::STATUS_QUEUED, $command->status);
        $this->assertNull($command->created_by_user_id);
        $this->assertSame('scheduler', $command->payload['source']);
        $this->assertSame(600, $command->payload['interval_seconds']);
        Queue::assertPushed(RunPythonActorJob::class, fn (RunPythonActorJob $job): bool => $job->actorName === PythonActorRunner::ACTOR_POLL_RECONCILIATION
            && $job->commandId === $command->id);
        $this->assertDatabaseHas('pipeline_events', [
            'command_id' => $command->id,
            'event_type' => 'scheduler.poll_reconciliation_actor_queued',
        ]);
        $event = PipelineEvent::query()->where('command_id', $command->id)->oldest()->firstOrFail();
        $this->assertSame('system_scheduler', $event->payload['actor_principal']);
        $this->assertNull($event->payload['actor_user_id']);
    }

    public function test_scheduled_poll_skips_when_disabled(): void
    {
        Queue::fake();
        config(['archibot.poll_interval_seconds' => 0]);

        $this->artisan('archibot:scheduled-poll')
            ->expectsOutput('Scheduled poll skipped because polling is disabled, not due, or already active.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('commands', 0);
    }

    public function test_scheduled_poll_skips_when_poll_command_is_active(): void
    {
        Queue::fake();
        config(['archibot.poll_interval_seconds' => 600]);
        Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_RUNNING,
            'payload' => ['source' => 'scheduler'],
        ]);

        $this->artisan('archibot:scheduled-poll')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('commands', 1);
    }

    public function test_scheduled_poll_waits_until_interval_after_success(): void
    {
        Queue::fake();
        config(['archibot.poll_interval_seconds' => 600]);
        Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_SUCCEEDED,
            'payload' => ['source' => 'scheduler'],
            'finished_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('archibot:scheduled-poll')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travel(6)->minutes();
        $this->artisan('archibot:scheduled-poll')->assertSuccessful();
        Queue::assertPushed(RunPythonActorJob::class, 1);
        $this->assertDatabaseCount('commands', 2);
    }

    public function test_recent_failed_scheduled_completion_suppresses_an_immediate_new_poll(): void
    {
        Queue::fake();
        config(['archibot.poll_interval_seconds' => 600]);
        Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_FAILED,
            'payload' => ['source' => 'scheduler'],
            'finished_at' => now()->subSeconds(599),
        ]);

        $this->assertNull(app(MaintenanceCommandDispatcher::class)->queueScheduledPollReconciliation());
        Queue::assertNothingPushed();
    }

    public function test_recent_permanent_failure_suppresses_an_immediate_new_poll(): void
    {
        Queue::fake();
        config(['archibot.poll_interval_seconds' => 600]);
        Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_FAILED_PERMANENT,
            'payload' => ['source' => 'scheduler'],
            'finished_at' => now()->subSeconds(599),
        ]);

        $this->assertNull(app(MaintenanceCommandDispatcher::class)->queueScheduledPollReconciliation());
        Queue::assertNothingPushed();
    }

    public function test_laravel_scheduler_checks_poll_due_state_each_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'archibot:scheduled-poll'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }
}
