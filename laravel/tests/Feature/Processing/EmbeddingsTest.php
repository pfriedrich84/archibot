<?php

namespace Tests\Feature\Processing;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PDO;
use Tests\TestCase;

class EmbeddingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_embeddings_page_resolves_paperless_entity_names(): void
    {
        $dbPath = tempnam(sys_get_temp_dir(), 'archibot_embeddings_');
        $pdo = new PDO('sqlite:'.$dbPath);
        $pdo->exec('CREATE TABLE doc_embedding_meta (document_id INTEGER, title TEXT, correspondent INTEGER, doctype INTEGER, storage_path INTEGER, tags_json TEXT, created_date TEXT, indexed_at TEXT)');
        $pdo->exec("INSERT INTO doc_embedding_meta VALUES (123, 'Invoice', 10, 20, 30, '[7]', '2026-05-05', '2026-05-07T10:00:00Z')");

        putenv('ARCHIBOT_PYTHON_DB_PATH='.$dbPath);
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake([
            'paperless.example/api/correspondents/*' => Http::response(['results' => [['id' => 10, 'name' => 'ACME GmbH']]], 200),
            'paperless.example/api/document_types/*' => Http::response(['results' => [['id' => 20, 'name' => 'Invoice']]], 200),
            'paperless.example/api/storage_paths/*' => Http::response(['results' => [['id' => 30, 'name' => 'Archive']]], 200),
            'paperless.example/api/tags/*' => Http::response(['results' => [['id' => 7, 'name' => 'Inbox']]], 200),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);

        try {
            $this->actingAs($user)
                ->get(route('embeddings.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('processing/Embeddings')
                    ->where('snapshot.total_embedded', 1)
                    ->where('snapshot.items.0.correspondent_name', 'ACME GmbH')
                    ->where('snapshot.items.0.doctype_name', 'Invoice')
                    ->where('snapshot.items.0.storage_path_name', 'Archive')
                    ->where('snapshot.items.0.tags.0.name', 'Inbox')
                );
        } finally {
            putenv('ARCHIBOT_PYTHON_DB_PATH');
            @unlink($dbPath);
        }
    }
}
