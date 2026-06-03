<?php

namespace Tests\Feature\Pipeline;

use App\Models\EmbeddingIndexState;
use App\Models\PipelineEvent;
use App\Models\PipelineRun;
use App\Services\Pipeline\DocumentPipelineStarter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentPipelineStartServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_open_creates_pending_run(): void
    {
        EmbeddingIndexState::query()->create(['status' => 'complete']);

        $result = app(DocumentPipelineStarter::class)->start(
            triggerSource: 'webhook',
            paperlessDocumentId: 42,
            paperlessModified: '2026-05-08T12:00:00Z',
        );

        $this->assertSame('created', $result->outcome);
        $this->assertTrue($result->created);
        $this->assertSame(PipelineRun::STATUS_PENDING, $result->pipelineRun->status);
        $this->assertSame('webhook', $result->pipelineRun->trigger_source);
        $this->assertSame(['webhook'], $result->pipelineRun->coalesced_sources);
        $this->assertSame('pipeline.start.pending', PipelineEvent::query()->firstOrFail()->event_type);
    }

    public function test_gate_closed_creates_blocked_run(): void
    {
        EmbeddingIndexState::query()->create(['status' => 'stale']);

        $result = app(DocumentPipelineStarter::class)->start(
            triggerSource: 'webhook',
            paperlessDocumentId: 42,
            paperlessModified: null,
        );

        $run = $result->pipelineRun;
        $this->assertSame('blocked', $result->outcome);
        $this->assertSame('embedding_index_not_ready', $result->blockedReason);
        $this->assertSame(PipelineRun::STATUS_BLOCKED, $run->status);
        $this->assertSame('blocked', $run->progress_current_phase);
        $this->assertSame('Waiting for embedding index to complete.', $run->progress_message);
        $this->assertSame('embedding_index_not_ready', $run->error_type);
        $this->assertSame('pipeline.blocked.embedding_index_not_ready', PipelineEvent::query()->firstOrFail()->event_type);
    }

    public function test_same_document_content_coalesces_sources_and_changed_modified_creates_new_run(): void
    {
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $starter = app(DocumentPipelineStarter::class);

        $first = $starter->start('webhook', 42, '2026-05-08T12:00:00Z');
        $second = $starter->start('poll', 42, '2026-05-08T12:00:00Z');
        $changed = $starter->start('poll', 42, '2026-05-09T12:00:00Z');

        $this->assertSame('created', $first->outcome);
        $this->assertSame('coalesced', $second->outcome);
        $this->assertFalse($second->created);
        $this->assertSame($first->pipelineRun->id, $second->pipelineRun->id);
        $this->assertEqualsCanonicalizing(['webhook', 'poll'], $second->pipelineRun->coalesced_sources);
        $this->assertSame('created', $changed->outcome);
        $this->assertNotSame($first->dedupeKey, $changed->dedupeKey);
        $this->assertDatabaseCount('pipeline_runs', 2);
        $this->assertDatabaseHas('pipeline_events', ['event_type' => 'pipeline.start.coalesced']);
    }

    public function test_manual_force_always_creates_new_run_for_identical_content(): void
    {
        EmbeddingIndexState::query()->create(['status' => 'complete']);
        $starter = app(DocumentPipelineStarter::class);

        $first = $starter->start(
            triggerSource: 'manual',
            paperlessDocumentId: 42,
            paperlessModified: '2026-05-08T12:00:00Z',
            reprocessRequested: true,
            reprocessMode: 'manual',
            forceNewRun: true,
            forceToken: 'first',
        );
        $second = $starter->start(
            triggerSource: 'manual',
            paperlessDocumentId: 42,
            paperlessModified: '2026-05-08T12:00:00Z',
            reprocessRequested: true,
            reprocessMode: 'manual',
            forceNewRun: true,
            forceToken: 'second',
        );

        $this->assertSame('force_created', $first->outcome);
        $this->assertSame('force_created', $second->outcome);
        $this->assertNotSame($first->dedupeKey, $second->dedupeKey);
        $this->assertDatabaseCount('pipeline_runs', 2);
    }

    public function test_laravel_dedupe_key_matches_python_known_vector(): void
    {
        $key = app(DocumentPipelineStarter::class)->dedupeKey(
            42,
            '2026-05-08T12:00:00Z',
            'hash',
        );

        $this->assertSame(hash('sha256', '42:2026-05-08T12:00:00Z:hash:v1'), $key);
    }

    public function test_dedupe_keys_match_shared_pipeline_start_contract_vectors(): void
    {
        $contract = $this->pipelineStartContract();
        $starter = app(DocumentPipelineStarter::class);

        foreach ($contract['dedupe_vectors'] as $vector) {
            $this->assertSame(
                $vector['expected_sha256'],
                $starter->dedupeKey(
                    $vector['paperless_document_id'],
                    $vector['paperless_modified'],
                    $vector['content_hash'],
                    $vector['pipeline_version'],
                ),
                $vector['name'],
            );
        }
    }

    public function test_force_dedupe_keys_match_shared_pipeline_start_contract_vectors(): void
    {
        $contract = $this->pipelineStartContract();
        $starter = app(DocumentPipelineStarter::class);

        foreach ($contract['force_vectors'] as $vector) {
            $this->assertSame(
                $vector['expected_sha256'],
                $starter->forceDedupeKey(
                    $vector['paperless_document_id'],
                    $vector['paperless_modified'],
                    $vector['content_hash'],
                    $vector['force_token'],
                    $vector['pipeline_version'],
                ),
                $vector['name'],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pipelineStartContract(): array
    {
        $path = dirname(base_path()).'/tests/fixtures/pipeline_start_contract.json';
        $contract = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($contract);

        return $contract;
    }
}
