<?php

namespace Tests\Feature\Processing;

use App\Models\AppSetting;
use App\Models\Command;
use App\Models\DocumentEmbedding;
use App\Models\EmbeddingIndexState;
use App\Models\User;
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

        $user = User::factory()->create(['paperless_token' => 'user-token']);

        $this->actingAs($user)
            ->get(route('embeddings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('processing/Embeddings')
                ->where('snapshot.status', EmbeddingIndexState::STATUS_COMPLETE)
                ->where('snapshot.embedding_model', 'qwen3-embedding:4b')
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

        $user = User::factory()->create(['paperless_token' => 'user-token']);

        $this->actingAs($user)
            ->get(route('embeddings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('processing/Embeddings')
                ->where('snapshot.status', EmbeddingIndexState::STATUS_COMPLETE)
                ->where('snapshot.ready', true)
                ->where('snapshot.embedding_model', 'qwen3-embedding:4b')
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

        $user = User::factory()->create(['paperless_token' => 'user-token']);

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

        $user = User::factory()->create(['paperless_token' => 'user-token']);

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
