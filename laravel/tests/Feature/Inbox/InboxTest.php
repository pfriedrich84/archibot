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

    public function test_authenticated_users_can_view_paperless_inbox_documents(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        AppSetting::put('paperless.inbox_tag_id', '7');
        Http::fake([
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
                ->where('error', null)
                ->where('documents.0.id', 123)
                ->where('documents.0.title', 'Inbox scan')
                ->where('documents.0.review.id', $suggestion->id)
                ->where('documents.0.review.proposed_title', 'Suggested inbox title')
            );

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Token user-token')
            && str_contains($request->url(), 'tags__id__all=7'));
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
                ->where('error', 'Paperless inbox tag ID is not configured.')
            );
    }

    public function test_inbox_page_reports_paperless_fetch_failure(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        AppSetting::put('paperless.inbox_tag_id', '7');
        Http::fake(['paperless.example/api/documents/*' => Http::response([], 500)]);

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
