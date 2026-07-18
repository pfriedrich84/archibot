<?php

namespace Tests\Feature\Processing;

use App\Models\AppSetting;
use App\Models\Command;
use App\Models\DocumentEmbedding;
use App\Models\EmbeddingIndexState;
use App\Models\User;
use App\Support\EmbeddingIndexSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EmbeddingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['archibot.paperless_url' => 'https://paperless.example']);
    }

    public function test_embeddings_page_uses_pgvector_embedding_status_not_python_database(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake([
            'paperless.example/api/documents/*' => Http::response(['count' => 3, 'results' => [['id' => 1]]], 200),
        ]);

        EmbeddingIndexState::query()->create([
            'status' => EmbeddingIndexState::STATUS_COMPLETE,
            'embedding_model' => 'qwen3-embedding:4b',
            'dimensions' => 2560,
            'document_count' => 3,
            'embedded_count' => 2,
            'failed_count' => 0,
        ]);
        DocumentEmbedding::query()->create([
            'paperless_document_id' => 10,
            'content_hash' => 'hash-10',
            'embedding_model' => 'qwen3-embedding:4b',
            'dimensions' => 2560,
            'embedding' => [0.1, 0.2],
        ]);
        DocumentEmbedding::query()->create([
            'paperless_document_id' => 11,
            'content_hash' => 'hash-11',
            'embedding_model' => 'qwen3-embedding:4b',
            'dimensions' => 2560,
            'embedding' => [0.3, 0.4],
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token', 'is_admin' => true]);

        $this->actingAs($user)
            ->get(route('embeddings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('processing/Embeddings')
                ->where('snapshot.status', EmbeddingIndexState::STATUS_COMPLETE)
                ->where('snapshot.embedding_model', 'Configured Model (ref:'.substr(hash('sha256', 'qwen3-embedding:4b'), 0, 12).')')
                ->where('snapshot.dimensions', 2560)
                ->where('snapshot.document_count', 3)
                ->where('snapshot.embedded_count', 2)
                ->where('snapshot.stored_embedding_rows', 2)
                ->where('snapshot.missing_count', 1)
                ->where('snapshot.failed_count', 0)
                ->missing('buildUrl')
                ->missing('markStaleUrl')
                ->missing('snapshot.db_path')
                ->missing('snapshot.items')
            );
    }

    public function test_embeddings_page_uses_completed_state_counts_when_legacy_reindex_succeeded(): void
    {
        AppSetting::put('embedding.model', 'qwen3-embedding:4b');
        EmbeddingIndexState::query()->create([
            'status' => EmbeddingIndexState::STATUS_COMPLETE,
            'document_count' => 138,
            'embedded_count' => 138,
            'failed_count' => 0,
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token', 'is_admin' => true]);

        $this->actingAs($user)
            ->get(route('embeddings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('processing/Embeddings')
                ->where('snapshot.status', EmbeddingIndexState::STATUS_COMPLETE)
                ->where('snapshot.ready', true)
                ->where('snapshot.embedding_model', 'Configured Model (ref:'.substr(hash('sha256', 'qwen3-embedding:4b'), 0, 12).')')
                ->where('snapshot.document_count', 138)
                ->where('snapshot.embedded_count', 138)
                ->where('snapshot.pgvector_embedded_count', 0)
                ->where('snapshot.missing_count', 0)
            );
    }

    public function test_embeddings_page_exposes_active_reindex_command_for_pgvector_progress(): void
    {
        EmbeddingIndexState::query()->create([
            'status' => 'building',
            'document_count' => 10,
            'embedded_count' => 4,
            'failed_count' => 1,
        ]);
        $command = Command::query()->create([
            'type' => Command::TYPE_REINDEX,
            'status' => Command::STATUS_RUNNING,
            'payload' => ['ui_surface' => 'maintenance_quick_controls'],
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token', 'is_admin' => true]);

        $this->actingAs($user)
            ->get(route('embeddings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('processing/Embeddings')
                ->where('snapshot.status', 'building')
                ->where('snapshot.document_count', 10)
                ->where('snapshot.embedded_count', 4)
                ->where('snapshot.failed_count', 1)
                ->where('latestEmbeddingBuildCommand.id', $command->id)
                ->where('latestEmbeddingBuildCommand.type', Command::TYPE_REINDEX)
                ->where('latestEmbeddingBuildCommand.status', Command::STATUS_RUNNING)
            );
    }

    public function test_embeddings_page_redacts_stored_free_form_failures(): void
    {
        EmbeddingIndexState::query()->create([
            'status' => 'failed',
            'document_count' => 1,
            'embedded_count' => 0,
            'failed_count' => 1,
            'error' => 'Bearer private-token with OCR and prompt content',
        ]);
        Command::query()->create([
            'type' => Command::TYPE_EMBEDDING_INDEX_BUILD,
            'status' => Command::STATUS_FAILED,
            'error' => 'private document content',
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $notice = 'Details redacted. Use the status, error type, identifiers and timeline to diagnose or recover this operation.';

        $this->actingAs($admin)
            ->get(route('embeddings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('snapshot.error', $notice)
                ->where('latestEmbeddingBuildCommand.error', $notice)
            );
    }

    public function test_embeddings_endpoint_types_every_snapshot_and_command_scalar_against_adversarial_values(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Command::query()->create([
            'type' => Command::TYPE_EMBEDDING_INDEX_BUILD,
            'status' => 'COMMAND_STATUS_SECRET<script>',
            'error' => 'COMMAND_ERROR_SECRET',
        ]);
        $this->mock(EmbeddingIndexSnapshot::class, function ($mock): void {
            $mock->shouldReceive('forRequest')->once()->andReturn([
                'id' => 'SNAPSHOT_ID_SECRET',
                'status' => 'SNAPSHOT_STATUS_SECRET<script>',
                'embedding_model' => 'sk-prod-secret123',
                'dimensions' => 'DIMENSIONS_SECRET',
                'document_count' => 'DOCUMENT_COUNT_SECRET',
                'document_count_known' => 'KNOWN_SECRET',
                'embedded_count' => 'EMBEDDED_SECRET',
                'stored_embedding_rows' => 'ROWS_SECRET',
                'pgvector_embedded_count' => 'PGVECTOR_SECRET',
                'missing_count' => 'MISSING_SECRET',
                'failed_count' => 'FAILED_SECRET',
                'started_at' => 'STARTED_SECRET',
                'completed_at' => 'COMPLETED_SECRET',
                'error' => 'SNAPSHOT_ERROR_SECRET',
                'document_count_error' => 'COUNT_ERROR_SECRET',
                'ready' => 'READY_SECRET',
                'arbitrary' => 'ARBITRARY_SECRET',
            ]);
        });

        $response = $this->actingAs($admin)->get(route('embeddings.index'));
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('snapshot.id', null)
            ->where('snapshot.dimensions', null)
            ->where('snapshot.document_count', 0)
            ->where('snapshot.document_count_known', false)
            ->where('snapshot.ready', false)
            ->missing('snapshot.arbitrary')
            ->where('latestEmbeddingBuildCommand.type', Command::TYPE_EMBEDDING_INDEX_BUILD)
        );

        $response->assertDontSee('sk-prod-secret123', escape: false);

        foreach (['SNAPSHOT_ID_SECRET', 'SNAPSHOT_STATUS_SECRET',
            'DIMENSIONS_SECRET', 'DOCUMENT_COUNT_SECRET', 'KNOWN_SECRET', 'EMBEDDED_SECRET',
            'ROWS_SECRET', 'PGVECTOR_SECRET', 'MISSING_SECRET', 'FAILED_SECRET', 'STARTED_SECRET',
            'COMPLETED_SECRET', 'SNAPSHOT_ERROR_SECRET', 'COUNT_ERROR_SECRET', 'READY_SECRET',
            'ARBITRARY_SECRET', 'COMMAND_STATUS_SECRET', 'COMMAND_ERROR_SECRET'] as $secret) {
            $response->assertDontSee($secret, escape: false);
        }
    }

    public function test_embeddings_page_infers_ready_from_pgvector_rows_when_state_is_missing(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake([
            'paperless.example/api/documents/*' => Http::response(['count' => 1, 'results' => [['id' => 1]]], 200),
        ]);

        DocumentEmbedding::query()->create([
            'paperless_document_id' => 10,
            'content_hash' => 'hash-10',
            'embedding_model' => 'qwen3-embedding:4b',
            'dimensions' => 2560,
            'embedding' => [0.1, 0.2],
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token', 'is_admin' => true]);

        $this->actingAs($user)
            ->get(route('embeddings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('processing/Embeddings')
                ->where('snapshot.status', EmbeddingIndexState::STATUS_COMPLETE)
                ->where('snapshot.ready', true)
                ->where('snapshot.document_count', 1)
                ->where('snapshot.embedded_count', 1)
                ->where('snapshot.missing_count', 0)
            );
    }
}
