<?php

namespace Tests\Feature\Workers;

use App\Models\Command;
use App\Models\EmbeddingIndexState;
use App\Models\EntityApproval;
use App\Models\ReviewSuggestion;
use App\Models\WorkerJob;
use App\Services\Workers\PythonWorkerCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PythonWorkerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_runs_json_cli_contract_and_records_result(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$input = $argv[array_search('--input', $argv, true) + 1];
$output = $argv[array_search('--output', $argv, true) + 1];
$data = json_decode(file_get_contents($input), true);
file_put_contents($output, json_encode(['ok' => true, 'type' => $data['type']]));
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $workerJob = WorkerJob::factory()->create(['type' => WorkerJob::TYPE_POLL]);
        app(PythonWorkerCommand::class)->run($workerJob);

        $workerJob->refresh();
        $this->assertSame(WorkerJob::STATUS_SUCCEEDED, $workerJob->status);
        $this->assertSame(0, $workerJob->exit_code);
        $this->assertSame([
            'ok' => true,
            'type' => WorkerJob::TYPE_POLL,
            'ingest' => ['review_suggestions_imported' => 0, 'entity_approvals_upserted' => 0, 'ocr_reviews_imported' => 0],
        ], $workerJob->result);
        $this->assertFileExists($workerJob->input_path);
        $this->assertFileExists($workerJob->output_path);

        @unlink($script);
    }

    public function test_captures_worker_progress_lines(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$output = $argv[array_search('--output', $argv, true) + 1];
echo 'PROGRESS '.json_encode([
    'phase' => 'embedding',
    'done' => 1,
    'total' => 3,
    'document_id' => 123,
    'message' => 'Document embedded',
]).PHP_EOL;
file_put_contents($output, json_encode(['ok' => true, 'progress' => ['phase' => 'finished', 'done' => 3, 'total' => 3]]));
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $workerJob = WorkerJob::factory()->create(['type' => WorkerJob::TYPE_REINDEX]);
        app(PythonWorkerCommand::class)->run($workerJob);

        $workerJob->refresh();
        $this->assertSame(WorkerJob::STATUS_SUCCEEDED, $workerJob->status);
        $this->assertSame(['phase' => 'finished', 'done' => 3, 'total' => 3], $workerJob->progress);

        @unlink($script);
    }

    public function test_strips_ansi_escape_codes_from_worker_logs(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$output = $argv[array_search('--output', $argv, true) + 1];
echo "\033[2m2026-05-08T11:14:28Z\033[0m [\033[32minfo\033[0m] initializing database".PHP_EOL;
file_put_contents($output, json_encode(['ok' => true]));
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $workerJob = WorkerJob::factory()->create(['type' => WorkerJob::TYPE_REINDEX_EMBED]);
        app(PythonWorkerCommand::class)->run($workerJob);

        $message = $workerJob->logs()->firstOrFail()->message;
        $this->assertSame('2026-05-08T11:14:28Z [info] initializing database', $message);

        @unlink($script);
    }

    public function test_ingests_python_emitted_review_suggestions_after_worker_success(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$input = $argv[array_search('--input', $argv, true) + 1];
$output = $argv[array_search('--output', $argv, true) + 1];
$data = json_decode(file_get_contents($input), true);
file_put_contents($output, json_encode([
    'ok' => true,
    'type' => $data['type'],
    'review_suggestions' => [[
        'paperless_document_id' => $data['payload']['paperless_document_id'],
        'confidence' => 92,
        'reasoning' => 'Emitted by Python worker.',
        'original' => ['title' => 'Scan 500'],
        'proposed' => ['title' => 'Invoice 500', 'tags' => [['id' => 9, 'name' => 'Invoices']]],
        'context_documents' => [['id' => 42, 'title' => 'Similar invoice']],
    ]],
]));
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $workerJob = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_PROCESS_DOCUMENT,
            'payload' => ['paperless_document_id' => 500],
        ]);
        app(PythonWorkerCommand::class)->run($workerJob);

        $workerJob->refresh();
        $this->assertSame(WorkerJob::STATUS_SUCCEEDED, $workerJob->status);
        $this->assertSame(['review_suggestions_imported' => 1, 'entity_approvals_upserted' => 0, 'ocr_reviews_imported' => 0], $workerJob->result['ingest']);

        $suggestion = ReviewSuggestion::query()->firstOrFail();
        $this->assertSame($workerJob->id, $suggestion->worker_job_id);
        $this->assertSame(500, $suggestion->paperless_document_id);
        $this->assertSame('Scan 500', $suggestion->original_title);
        $this->assertSame('Invoice 500', $suggestion->proposed_title);
        $this->assertSame([['id' => 9, 'name' => 'Invoices']], $suggestion->proposed_tags);

        @unlink($script);
    }

    public function test_valid_partial_output_is_ingested_when_python_exits_non_zero(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$input = $argv[array_search('--input', $argv, true) + 1];
$output = $argv[array_search('--output', $argv, true) + 1];
$data = json_decode(file_get_contents($input), true);
file_put_contents($output, json_encode([
    'ok' => false,
    'review_suggestions' => [[
        'source_suggestion_id' => 777,
        'paperless_document_id' => $data['payload']['paperless_document_id'],
        'confidence' => 88,
        'proposed' => ['title' => 'Partial invoice'],
    ]],
]));
fwrite(STDERR, 'worker failed after writing partial output');
exit(1);
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $workerJob = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_PROCESS_DOCUMENT,
            'payload' => ['paperless_document_id' => 500],
        ]);
        app(PythonWorkerCommand::class)->run($workerJob);

        $workerJob->refresh();
        $this->assertSame(WorkerJob::STATUS_FAILED, $workerJob->status);
        $this->assertSame(1, $workerJob->exit_code);
        $this->assertSame(['review_suggestions_imported' => 1, 'entity_approvals_upserted' => 0, 'ocr_reviews_imported' => 0], $workerJob->result['ingest']);
        $this->assertDatabaseHas('review_suggestions', [
            'paperless_document_id' => 500,
            'source_suggestion_id' => 777,
            'proposed_title' => 'Partial invoice',
        ]);

        @unlink($script);
    }

    public function test_commit_review_worker_updates_review_commit_status(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$output = $argv[array_search('--output', $argv, true) + 1];
file_put_contents($output, json_encode([
    'ok' => true,
    'result' => ['source_suggestion_id' => 321, 'status' => 'committed', 'committed' => true],
]));
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $suggestion = ReviewSuggestion::factory()->create([
            'source_suggestion_id' => 321,
            'commit_status' => ReviewSuggestion::COMMIT_STATUS_QUEUED,
        ]);
        $workerJob = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_COMMIT_REVIEW,
            'payload' => ['review_suggestion_id' => $suggestion->id, 'source_suggestion_id' => 321],
        ]);

        app(PythonWorkerCommand::class)->run($workerJob);

        $this->assertSame(ReviewSuggestion::COMMIT_STATUS_COMMITTED, $suggestion->refresh()->commit_status);
        $this->assertSame($workerJob->id, $suggestion->commit_worker_job_id);

        @unlink($script);
    }

    public function test_worker_command_records_lease_and_heartbeat_metadata(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$output = $argv[array_search('--output', $argv, true) + 1];
file_put_contents($output, json_encode(['ok' => true]));
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $workerJob = WorkerJob::factory()->create();
        app(PythonWorkerCommand::class)->run($workerJob);

        $workerJob->refresh();
        $this->assertSame(WorkerJob::STATUS_SUCCEEDED, $workerJob->status);
        $this->assertNotNull($workerJob->worker_id);
        $this->assertNotNull($workerJob->heartbeat_at);
        $this->assertNull($workerJob->lease_expires_at);

        @unlink($script);
    }

    public function test_worker_command_does_not_run_when_another_active_lease_owns_job(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'archibot-worker-marker-');
        @unlink($marker);
        $script = $this->writeWorkerStub(<<<PHP
file_put_contents('$marker', 'ran');
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $workerJob = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_RUNNING,
            'worker_id' => 'other-worker',
            'lease_expires_at' => now()->addMinutes(5),
            'heartbeat_at' => now(),
        ]);

        app(PythonWorkerCommand::class)->run($workerJob);

        $workerJob->refresh();
        $this->assertSame(WorkerJob::STATUS_RUNNING, $workerJob->status);
        $this->assertSame('other-worker', $workerJob->worker_id);
        $this->assertFileDoesNotExist($marker);

        @unlink($script);
    }

    public function test_worker_command_can_take_over_expired_running_lease(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$output = $argv[array_search('--output', $argv, true) + 1];
file_put_contents($output, json_encode(['ok' => true]));
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $workerJob = WorkerJob::factory()->create([
            'status' => WorkerJob::STATUS_RUNNING,
            'worker_id' => 'stale-worker',
            'lease_expires_at' => now()->subMinute(),
            'heartbeat_at' => now()->subMinutes(2),
            'started_at' => now()->subMinutes(3),
        ]);

        app(PythonWorkerCommand::class)->run($workerJob);

        $workerJob->refresh();
        $this->assertSame(WorkerJob::STATUS_SUCCEEDED, $workerJob->status);
        $this->assertNotSame('stale-worker', $workerJob->worker_id);
        $this->assertNotNull($workerJob->heartbeat_at);
        $this->assertNull($workerJob->lease_expires_at);

        @unlink($script);
    }

    public function test_worker_command_passes_force_for_supported_worker_types(): void
    {
        $command = app(PythonWorkerCommand::class);
        $method = new \ReflectionMethod(PythonWorkerCommand::class, 'commandFor');
        $method->setAccessible(true);

        foreach ([
            WorkerJob::TYPE_POLL,
            WorkerJob::TYPE_PROCESS_DOCUMENT,
            WorkerJob::TYPE_REINDEX_OCR,
        ] as $type) {
            $workerJob = WorkerJob::factory()->make([
                'type' => $type,
                'payload' => ['force' => true, 'paperless_document_id' => 123],
            ]);

            $argv = $method->invoke($command, $workerJob, '/tmp/input.json', '/tmp/output.json');

            $this->assertContains('--input', $argv);
            $this->assertContains('/tmp/input.json', $argv);
            $this->assertContains('--output', $argv);
            $this->assertContains('/tmp/output.json', $argv);
            $this->assertContains('--force', $argv);
        }
    }

    public function test_worker_command_does_not_pass_force_for_unsupported_worker_types(): void
    {
        $workerJob = WorkerJob::factory()->make([
            'type' => WorkerJob::TYPE_REINDEX_EMBED,
            'payload' => ['force' => true],
        ]);
        $method = new \ReflectionMethod(PythonWorkerCommand::class, 'commandFor');
        $method->setAccessible(true);

        $argv = $method->invoke(app(PythonWorkerCommand::class), $workerJob, '/tmp/input.json', '/tmp/output.json');

        $this->assertNotContains('--force', $argv);
    }

    public function test_sync_entity_approval_worker_updates_sync_status(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$output = $argv[array_search('--output', $argv, true) + 1];
file_put_contents($output, json_encode([
    'ok' => true,
    'result' => ['synced' => true, 'patched_docs' => 2, 'updated_pending' => 1],
]));
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $entity = EntityApproval::factory()->create([
            'sync_status' => EntityApproval::SYNC_STATUS_QUEUED,
        ]);
        $workerJob = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_SYNC_ENTITY_APPROVAL,
            'payload' => ['entity_approval_id' => $entity->id, 'action' => 'approved'],
        ]);

        app(PythonWorkerCommand::class)->run($workerJob);

        $this->assertSame(EntityApproval::SYNC_STATUS_SYNCED, $entity->refresh()->sync_status);
        $this->assertSame($workerJob->id, $entity->sync_worker_job_id);

        @unlink($script);
    }

    public function test_successful_embedding_reindex_worker_updates_embedding_index_state(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$output = $argv[array_search('--output', $argv, true) + 1];
file_put_contents($output, json_encode([
    'ok' => true,
    'result' => [
        'indexed' => 7,
        'failed' => 0,
        'progress' => ['done' => 7, 'total' => 7, 'failed' => 0],
    ],
]));
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $command = Command::query()->create([
            'type' => Command::TYPE_EMBEDDING_INDEX_BUILD,
            'status' => Command::STATUS_QUEUED,
            'payload' => [],
        ]);
        $workerJob = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_REINDEX_EMBED,
            'payload' => ['command_id' => $command->id],
        ]);
        app(PythonWorkerCommand::class)->run($workerJob);

        $state = EmbeddingIndexState::query()->firstOrFail();
        $this->assertSame(EmbeddingIndexState::STATUS_COMPLETE, $state->status);
        $this->assertSame(7, $state->document_count);
        $this->assertSame(7, $state->embedded_count);
        $this->assertSame(0, $state->failed_count);
        $this->assertNull($state->error);
        $this->assertSame(Command::STATUS_SUCCEEDED, $command->refresh()->status);
        $this->assertNull($command->error);
        $this->assertNotNull($command->finished_at);

        @unlink($script);
    }

    public function test_failed_embedding_reindex_worker_updates_embedding_index_state(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$output = $argv[array_search('--output', $argv, true) + 1];
file_put_contents($output, json_encode(['ok' => false, 'error' => 'boom']));
fwrite(STDERR, 'boom');
exit(1);
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $workerJob = WorkerJob::factory()->create(['type' => WorkerJob::TYPE_REINDEX]);
        app(PythonWorkerCommand::class)->run($workerJob);

        $state = EmbeddingIndexState::query()->firstOrFail();
        $this->assertSame(EmbeddingIndexState::STATUS_FAILED, $state->status);
        $this->assertSame('boom', $state->error);

        @unlink($script);
    }

    public function test_commit_review_worker_marks_review_commit_failed_when_python_does_not_commit(): void
    {
        $script = $this->writeWorkerStub(<<<'PHP'
$output = $argv[array_search('--output', $argv, true) + 1];
file_put_contents($output, json_encode([
    'ok' => true,
    'result' => ['source_suggestion_id' => 321, 'status' => 'error', 'committed' => false],
]));
PHP);

        Config::set('archibot_workers.python_binary', $script);

        $suggestion = ReviewSuggestion::factory()->create([
            'source_suggestion_id' => 321,
            'commit_status' => ReviewSuggestion::COMMIT_STATUS_QUEUED,
        ]);
        $workerJob = WorkerJob::factory()->create([
            'type' => WorkerJob::TYPE_COMMIT_REVIEW,
            'payload' => ['review_suggestion_id' => $suggestion->id, 'source_suggestion_id' => 321],
        ]);

        app(PythonWorkerCommand::class)->run($workerJob);

        $this->assertSame(ReviewSuggestion::COMMIT_STATUS_FAILED, $suggestion->refresh()->commit_status);
        $this->assertSame($workerJob->id, $suggestion->commit_worker_job_id);

        @unlink($script);
    }

    private function writeWorkerStub(string $body): string
    {
        $script = tempnam(sys_get_temp_dir(), 'archibot-worker-');
        file_put_contents($script, "#!/usr/bin/env php\n<?php\n".$body);
        chmod($script, 0755);

        return $script;
    }
}
