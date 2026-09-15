<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Command;
use App\Models\PipelineEvent;
use App\Models\TemporalOutboxIntent;
use App\Services\Pipeline\MaintenanceCommandDispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        Queue::assertNothingPushed();
        $this->assertDatabaseHas('temporal_outbox_intents', [
            'workflow_id' => "archibot/poll-reconciliation/{$command->id}",
            'workflow_type' => 'archibot.poll_reconciliation',
            'status' => TemporalOutboxIntent::STATUS_PENDING,
        ]);
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
            'finished_at' => now('UTC')->subMinutes(5),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->artisan('archibot:scheduled-poll')->assertSuccessful();
        Queue::assertNothingPushed();

        Command::query()->firstOrFail()->update([
            'finished_at' => now('UTC')->subMinutes(11),
        ]);
        $this->artisan('archibot:scheduled-poll')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('temporal_outbox_intents', 1);
        $this->assertDatabaseCount('commands', 2);
    }

    public function test_saved_poll_interval_overrides_boot_environment_value(): void
    {
        Queue::fake();
        config(['archibot.poll_interval_seconds' => 60]);
        AppSetting::put('worker.poll_interval_seconds', '600');
        Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_SUCCEEDED,
            'payload' => ['source' => 'scheduler'],
            'finished_at' => now('UTC')->subMinutes(5),
        ]);

        $this->assertNull(app(MaintenanceCommandDispatcher::class)->queueScheduledPollReconciliation());
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('commands', 1);
    }

    public function test_database_utc_completion_is_recent_when_app_timezone_is_ahead(): void
    {
        Queue::fake();
        config([
            'app.timezone' => 'Europe/Vienna',
            'archibot.poll_interval_seconds' => 600,
        ]);
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            $utcNow = now('UTC');
            DB::table('commands')->insert([
                'type' => Command::TYPE_POLL_RECONCILIATION,
                'status' => Command::STATUS_SUCCEEDED,
                'payload' => json_encode(['source' => 'scheduler'], JSON_THROW_ON_ERROR),
                'finished_at' => $utcNow->copy()->subMinute()->format('Y-m-d H:i:s'),
                'created_at' => $utcNow->copy()->subMinutes(2)->format('Y-m-d H:i:s'),
                'updated_at' => $utcNow->copy()->subMinute()->format('Y-m-d H:i:s'),
            ]);

            $this->assertNull(app(MaintenanceCommandDispatcher::class)->queueScheduledPollReconciliation());
            Queue::assertNothingPushed();
            $this->assertDatabaseCount('commands', 1);
        } finally {
            date_default_timezone_set($previousTimezone);
        }
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
