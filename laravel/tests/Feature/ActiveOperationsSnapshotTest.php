<?php

namespace Tests\Feature;

use App\Models\ActorExecution;
use App\Models\Command;
use App\Support\ActiveOperationsSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActiveOperationsSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_progress_is_linked_by_command_id(): void
    {
        $first = Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_RUNNING,
            'payload' => [],
        ]);
        $second = Command::query()->create([
            'type' => Command::TYPE_POLL_RECONCILIATION,
            'status' => Command::STATUS_RUNNING,
            'payload' => [],
        ]);
        ActorExecution::query()->create([
            'command_id' => $first->id,
            'actor_name' => 'reconcile_inbox_documents',
            'status' => ActorExecution::STATUS_RUNNING,
            'progress_total' => 10,
            'progress_done' => 3,
            'progress_message' => 'first command',
        ]);
        ActorExecution::query()->create([
            'command_id' => $second->id,
            'actor_name' => 'reconcile_inbox_documents',
            'status' => ActorExecution::STATUS_RUNNING,
            'progress_total' => 20,
            'progress_done' => 17,
            'progress_message' => 'second command',
        ]);

        $items = collect(app(ActiveOperationsSnapshot::class)->make(limit: 8)['items'])
            ->keyBy('key');

        $this->assertSame(3, $items->get("command-{$first->id}")['progress_done']);
        $this->assertSame('first command', $items->get("command-{$first->id}")['progress_message']);
        $this->assertSame(17, $items->get("command-{$second->id}")['progress_done']);
        $this->assertSame('second command', $items->get("command-{$second->id}")['progress_message']);
    }
}
