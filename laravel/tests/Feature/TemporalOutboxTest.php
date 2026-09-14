<?php

namespace Tests\Feature;

use App\Models\TemporalOutboxIntent;
use App\Services\Temporal\TemporalOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class TemporalOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_start_intent_is_persisted_once_with_stable_identity(): void
    {
        $key = '6a45dd09-652d-4387-8634-9755db7f0f98';
        $outbox = app(TemporalOutbox::class);

        $first = $outbox->startWorkflow(
            $key,
            "archibot/runtime-probe/{$key}",
            'archibot.runtime_probe',
            ['request_id' => $key],
        );
        $second = $outbox->startWorkflow(
            $key,
            "archibot/runtime-probe/{$key}",
            'archibot.runtime_probe',
            ['request_id' => $key],
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('temporal_outbox_intents', 1);
        $this->assertSame(TemporalOutboxIntent::STATUS_PENDING, $first->status);
        $this->assertSame('archibot-orchestration', $first->task_queue);
    }

    public function test_intent_key_collision_with_changed_payload_fails_closed(): void
    {
        $key = '6a45dd09-652d-4387-8634-9755db7f0f98';
        $outbox = app(TemporalOutbox::class);
        $outbox->startWorkflow(
            $key,
            "archibot/runtime-probe/{$key}",
            'archibot.runtime_probe',
            ['request_id' => $key],
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Temporal intent key collision changed payload');
        $outbox->startWorkflow(
            $key,
            "archibot/runtime-probe/{$key}",
            'archibot.runtime_probe',
            ['request_id' => 'changed'],
        );
    }

    public function test_available_at_is_persisted_as_utc_when_the_application_uses_a_local_timezone(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 22:27:00', 'Europe/Vienna'));

        try {
            app(TemporalOutbox::class)->startWorkflow(
                '39df97c0-ea27-49f5-8e47-a409633495af',
                'archibot/runtime-probe/39df97c0-ea27-49f5-8e47-a409633495af',
                'archibot.runtime_probe',
                ['request_id' => '39df97c0-ea27-49f5-8e47-a409633495af'],
            );
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertSame(
            '2026-09-14 20:27:00',
            DB::table('temporal_outbox_intents')->value('available_at'),
        );
    }

    public function test_signal_and_cancel_intents_preserve_their_workflow_identity(): void
    {
        $outbox = app(TemporalOutbox::class);
        $workflowId = 'archibot/document/261';

        $signal = $outbox->signalWorkflow(
            '55f789ed-a9d2-411b-818f-0cc73c9eb34b',
            $workflowId,
            'review_accepted',
            ['review_suggestion_id' => 34],
        );
        $cancel = $outbox->cancelWorkflow(
            'fc9ed2ea-fdbd-4f8f-9703-816a564bcf77',
            $workflowId,
            ['reason' => 'operator_request'],
        );

        $this->assertSame(TemporalOutboxIntent::OPERATION_SIGNAL, $signal->operation);
        $this->assertSame('review_accepted', $signal->signal_name);
        $this->assertNull($signal->workflow_type);
        $this->assertNull($signal->task_queue);
        $this->assertSame(TemporalOutboxIntent::OPERATION_CANCEL, $cancel->operation);
        $this->assertNull($cancel->signal_name);
        $this->assertSame($workflowId, $cancel->workflow_id);
        $this->assertDatabaseCount('temporal_outbox_intents', 2);
    }

    public function test_probe_command_reuses_an_explicit_request_id(): void
    {
        $key = '6a45dd09-652d-4387-8634-9755db7f0f98';

        $this->artisan('archibot:temporal-probe', ['--request-id' => $key])
            ->expectsOutputToContain($key)
            ->assertSuccessful();
        $this->artisan('archibot:temporal-probe', ['--request-id' => $key])
            ->assertSuccessful();

        $this->assertDatabaseCount('temporal_outbox_intents', 1);
    }

    public function test_probe_command_rejects_non_uuid_request_id(): void
    {
        $this->artisan('archibot:temporal-probe', ['--request-id' => 'unsafe'])
            ->expectsOutput('The --request-id value must be a UUID.')
            ->assertFailed();

        $this->assertDatabaseCount('temporal_outbox_intents', 0);
    }
}
