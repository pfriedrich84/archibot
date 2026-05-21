<?php

namespace Tests\Feature\Processing;

use App\Models\AppSetting;
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
                ->missing('snapshot.db_path')
                ->missing('snapshot.items')
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
