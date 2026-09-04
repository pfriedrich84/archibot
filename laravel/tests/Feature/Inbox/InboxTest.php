<?php

namespace Tests\Feature\Inbox;

use App\Models\AppSetting;
use App\Models\ReviewSuggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['archibot.paperless_url' => 'https://paperless.example']);
    }

    public function test_authenticated_users_can_view_paperless_inbox_documents(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        AppSetting::put('paperless.inbox_tag_id', '7');
        Http::fake([
            'paperless.example/api/correspondents/*' => Http::response(['results' => [['id' => 10, 'name' => 'ACME GmbH']]], 200),
            'paperless.example/api/document_types/*' => Http::response(['results' => [['id' => 20, 'name' => 'Invoice']]], 200),
            'paperless.example/api/tags/*' => Http::response(['results' => [['id' => 7, 'name' => 'Inbox']]], 200),
            'paperless.example/api/documents/*' => Http::response([
                'results' => [
                    [
                        'id' => 123,
                        'title' => 'Inbox scan',
                        'created_date' => '2026-05-05',
                        'correspondent' => 10,
                        'document_type' => 20,
                        'tags' => [7],
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);
        $suggestion = ReviewSuggestion::factory()->create([
            'paperless_document_id' => 123,
            'proposed_title' => 'Suggested inbox title',
        ]);

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inbox/Index')
                ->where('inboxTagId', 7)
                ->where('inboxTagName', 'Inbox')
                ->where('error', null)
                ->where('kpis.total', 1)
                ->where('kpis.with_review', 1)
                ->where('kpis.pending_review', 1)
                ->where('documents.0.id', 123)
                ->where('documents.0.title', 'Inbox scan')
                ->where('documents.0.correspondent_name', 'ACME GmbH')
                ->where('documents.0.document_type_name', 'Invoice')
                ->where('documents.0.tags.0.name', 'Inbox')
                ->where('documents.0.review.id', $suggestion->id)
                ->where('documents.0.review.proposed_title', 'Suggested inbox title')
            );

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Token user-token')
            && str_contains($request->url(), 'tags__id__all=7'));
    }

    public function test_inbox_page_loads_all_paperless_pages(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        AppSetting::put('paperless.inbox_tag_id', '7');
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/documents/')) {
                return str_contains($request->url(), 'page=2')
                    ? Http::response([
                        'count' => 36,
                        'next' => null,
                        'results' => collect(range(26, 36))
                            ->map(fn (int $id): array => ['id' => $id, 'title' => "Inbox {$id}"])
                            ->all(),
                    ])
                    : Http::response([
                        'count' => 36,
                        'next' => 'https://paperless.example/api/documents/?page=2&page_size=25&tags__id__all=7',
                        'results' => collect(range(1, 25))
                            ->map(fn (int $id): array => ['id' => $id, 'title' => "Inbox {$id}"])
                            ->all(),
                    ]);
            }

            return Http::response(['results' => []]);
        });

        $user = User::factory()->create(['paperless_token' => 'user-token']);

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.total', 36)
                ->has('documents', 36)
                ->where('documents.35.id', 36)
            );

        Http::assertSentCount(5);
    }

    public function test_inbox_page_reports_missing_configuration(): void
    {
        $user = User::factory()->create(['paperless_token' => 'user-token']);

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inbox/Index')
                ->where('documents', [])
                ->where('kpis.total', 0)
                ->where('error', 'Paperless inbox tag ID is not configured.')
            );
    }

    public function test_inbox_page_reports_paperless_fetch_failure(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        AppSetting::put('paperless.inbox_tag_id', '7');
        Http::fake([
            'paperless.example/api/correspondents/*' => Http::response(['results' => []], 200),
            'paperless.example/api/document_types/*' => Http::response(['results' => []], 200),
            'paperless.example/api/tags/*' => Http::response(['results' => []], 200),
            'paperless.example/api/documents/*' => Http::response([], 500),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);

        $this->actingAs($user)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inbox/Index')
                ->where('documents', [])
                ->where('error', 'Could not fetch Paperless documents.')
            );
    }
}
