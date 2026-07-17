<?php

namespace Tests\Feature\Ocr;

use App\Models\AppSetting;
use App\Models\OcrReview;
use App\Models\User;
use App\Services\Settings\PythonRuntimeConfigExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OcrReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['archibot.paperless_url' => 'https://paperless.example']);
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::preventStrayRequests();
    }

    public function test_store_requires_change_permission_and_preserves_local_snapshots_without_patch(): void
    {
        Http::fake([
            'paperless.example/api/documents/123/' => Http::sequence()
                ->push([], 200, ['Allow' => 'GET, PATCH, OPTIONS'])
                ->push(['id' => 123, 'content' => 'Synthetic original snapshot'], 200)
                ->push([], 200, ['Allow' => 'GET, PATCH, OPTIONS']),
        ]);
        $user = User::factory()->create(['paperless_token' => 'creator-token']);

        $response = $this->actingAs($user)->post(route('ocr-reviews.store'), [
            'paperless_document_id' => 123,
            'ocr_content' => 'Synthetic corrected snapshot',
        ]);

        $review = OcrReview::query()->firstOrFail();
        $response->assertRedirect(route('ocr-reviews.show', $review));
        $this->assertSame('Synthetic original snapshot', $review->original_content);
        $this->assertSame('Synthetic corrected snapshot', $review->ocr_content);
        $this->assertSame(OcrReview::STATUS_PENDING, $review->status);
        Http::assertSentCount(3);
        $this->assertNoPaperlessContentPatchWasSent();
    }

    public function test_store_fails_closed_before_fetching_content_when_change_permission_is_denied(): void
    {
        Http::fake([
            'paperless.example/api/documents/123/' => Http::response([], 200, ['Allow' => 'GET, OPTIONS']),
        ]);
        $user = User::factory()->create(['paperless_token' => 'denied-token']);

        $this->actingAs($user)->post(route('ocr-reviews.store'), [
            'paperless_document_id' => 123,
            'ocr_content' => 'Synthetic denied correction',
        ])->assertForbidden();

        $this->assertDatabaseCount('ocr_reviews', 0);
        Http::assertSentCount(1);
        $this->assertNoPaperlessContentPatchWasSent();
    }

    public function test_store_fails_closed_when_content_fetch_fails_after_permission_check(): void
    {
        Http::fake([
            'paperless.example/api/documents/123/' => Http::sequence()
                ->push([], 200, ['Allow' => 'GET, PATCH, OPTIONS'])
                ->push([], 500),
        ]);
        $user = User::factory()->create(['paperless_token' => 'creator-token']);

        $this->actingAs($user)->post(route('ocr-reviews.store'), [
            'paperless_document_id' => 123,
            'ocr_content' => 'Must not persist after content API failure',
        ])->assertStatus(500);

        $this->assertDatabaseCount('ocr_reviews', 0);
        Http::assertSentCount(2);
        $this->assertNoPaperlessContentPatchWasSent();
    }

    public function test_store_rechecks_change_permission_immediately_before_local_mutation(): void
    {
        Http::fake([
            'paperless.example/api/documents/123/' => Http::sequence()
                ->push([], 200, ['Allow' => 'GET, PATCH, OPTIONS'])
                ->push(['id' => 123, 'content' => 'Synthetic original snapshot'], 200)
                ->push([], 403),
        ]);
        $user = User::factory()->create(['paperless_token' => 'creator-token']);

        $this->actingAs($user)->post(route('ocr-reviews.store'), [
            'paperless_document_id' => 123,
            'ocr_content' => 'Must not persist after permission revocation',
        ])->assertForbidden();

        $this->assertDatabaseCount('ocr_reviews', 0);
        Http::assertSentCount(3);
        $this->assertNoPaperlessContentPatchWasSent();
    }

    public function test_index_filters_rows_totals_and_pagination_before_serialization(): void
    {
        $user = User::factory()->create(['paperless_token' => 'reader-token']);
        $otherUser = User::factory()->create();
        foreach (range(1, 30) as $documentId) {
            $creator = $documentId % 2 === 0 ? $otherUser : $user;
            $this->review($creator, $documentId, "Accessible original {$documentId}", "Accessible correction {$documentId}");
        }
        $denied = $this->review($otherUser, 901, 'Denied original marker', 'Denied correction marker');
        $this->review($otherUser, 902, 'Other denied original', 'Other denied correction');

        Http::fake(function (HttpRequest $request) {
            $documentId = (int) basename(trim($request->url(), '/'));

            return $documentId <= 30
                ? Http::response(['id' => $documentId], 200)
                : Http::response([], 404);
        });

        $this->actingAs($user)
            ->get(route('ocr-reviews.index', ['page' => 2]))
            ->assertOk()
            ->assertDontSee('Denied original marker')
            ->assertDontSee('Denied correction marker')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ocr/Index')
                ->where('reviews.total', 30)
                ->where('reviews.current_page', 2)
                ->has('reviews.data', 5)
                ->where('reviews.data', fn ($rows): bool => collect($rows)->every(
                    fn (array $row): bool => $row['paperless_document_id'] <= 30
                        && ! array_key_exists('original_content', $row)
                        && ! array_key_exists('ocr_content', $row)
                ))
            );

        $this->assertNotNull($denied->fresh());
    }

    public function test_index_denies_archibot_admin_without_live_paperless_view_permission(): void
    {
        $creator = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        $this->review($creator, 456, 'Admin-denied original marker', 'Admin-denied correction marker');
        Http::fake([
            'paperless.example/api/documents/456/' => Http::response([], 403),
        ]);

        $this->actingAs($admin)
            ->get(route('ocr-reviews.index'))
            ->assertOk()
            ->assertDontSee('Admin-denied original marker')
            ->assertDontSee('Admin-denied correction marker')
            ->assertInertia(fn (Assert $page) => $page
                ->where('reviews.total', 0)
                ->has('reviews.data', 0)
            );
    }

    public function test_index_fails_closed_on_paperless_api_errors_and_does_not_load_ocr_content_first(): void
    {
        $user = User::factory()->create(['paperless_token' => 'reader-token']);
        $this->review($user, 456, 'Sensitive marker must not load', 'Sensitive corrected marker');
        $queriesBeforePermission = [];
        DB::listen(function ($query) use (&$queriesBeforePermission): void {
            $queriesBeforePermission[] = strtolower($query->sql);
        });
        Http::fake(function () use (&$queriesBeforePermission) {
            $this->assertFalse(collect($queriesBeforePermission)->contains(
                fn (string $sql): bool => str_contains($sql, 'original_content') || str_contains($sql, 'ocr_content')
            ));

            return Http::response([], 500);
        });

        $this->actingAs($user)
            ->get(route('ocr-reviews.index'))
            ->assertOk()
            ->assertDontSee('Sensitive marker must not load')
            ->assertInertia(fn (Assert $page) => $page
                ->where('reviews.total', 0)
                ->has('reviews.data', 0)
            );
    }

    public function test_show_requires_live_view_permission_for_users_and_admins_without_existence_leak(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($owner, 456, 'Synthetic original', 'Synthetic correction');
        $allowed = User::factory()->create(['paperless_token' => 'allowed-token']);
        $denied = User::factory()->create(['paperless_token' => 'denied-token']);
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        Http::fake(function (HttpRequest $request) {
            return $request->hasHeader('Authorization', 'Token allowed-token')
                ? Http::response(['id' => 456], 200)
                : Http::response([], 403);
        });

        $this->actingAs($allowed)
            ->get(route('ocr-reviews.show', $review))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('review.original_content', 'Synthetic original')
                ->where('review.ocr_content', 'Synthetic correction')
            );
        $this->actingAs($denied)->get(route('ocr-reviews.show', $review))->assertNotFound();
        $this->actingAs($admin)->get(route('ocr-reviews.show', $review))->assertNotFound();
        $this->actingAs($denied)->get(route('ocr-reviews.show', 999999))->assertNotFound();
    }

    public function test_denied_show_authorizes_identifier_before_loading_local_content(): void
    {
        $owner = User::factory()->create();
        $review = $this->review($owner, 456, 'Never-loaded original marker', 'Never-loaded correction marker');
        $denied = User::factory()->create(['paperless_token' => 'denied-token']);
        $queriesBeforePermission = [];
        DB::listen(function ($query) use (&$queriesBeforePermission): void {
            $queriesBeforePermission[] = strtolower($query->sql);
        });
        Http::fake(function () use (&$queriesBeforePermission) {
            $ocrQueries = collect($queriesBeforePermission)->filter(
                fn (string $sql): bool => str_contains($sql, 'from "ocr_reviews"')
                    || str_contains($sql, 'from `ocr_reviews`')
            );
            $this->assertTrue($ocrQueries->isNotEmpty());
            $this->assertFalse($ocrQueries->contains(
                fn (string $sql): bool => str_contains($sql, 'original_content') || str_contains($sql, 'ocr_content')
            ));

            return Http::response([], 403);
        });

        $this->actingAs($denied)
            ->get(route('ocr-reviews.show', $review))
            ->assertNotFound()
            ->assertDontSee('Never-loaded original marker')
            ->assertDontSee('Never-loaded correction marker');
    }

    public function test_approve_is_local_only_and_requires_current_change_permission(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create(['paperless_token' => 'reviewer-token']);
        $review = $this->review($owner, 123, 'Original retained', 'Correction retained');
        Http::fake([
            'paperless.example/api/documents/123/' => Http::response([], 200, ['Allow' => 'GET, PATCH, OPTIONS']),
        ]);

        $this->actingAs($user)
            ->post(route('ocr-reviews.approve', $review), ['approved_content' => 'Locally approved snapshot'])
            ->assertRedirect(route('ocr-reviews.show', $review));

        $review->refresh();
        $this->assertSame(OcrReview::STATUS_APPROVED, $review->status);
        $this->assertSame('Original retained', $review->original_content);
        $this->assertSame('Correction retained', $review->ocr_content);
        $this->assertSame('Locally approved snapshot', $review->approved_content);
        $this->assertNull($review->written_back_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'ocr_review.approved', 'target_id' => (string) $review->id]);
        $this->assertNoPaperlessContentPatchWasSent();
    }

    public function test_permission_revocation_after_list_blocks_approve_without_mutation(): void
    {
        $user = User::factory()->create(['paperless_token' => 'reviewer-token']);
        $review = $this->review($user, 123, 'Original retained', 'Correction retained');
        Http::fake(function (HttpRequest $request) {
            return $request->method() === 'GET'
                ? Http::response(['id' => 123], 200)
                : Http::response([], 403);
        });

        $this->actingAs($user)->get(route('ocr-reviews.index'))->assertOk();
        $this->actingAs($user)
            ->post(route('ocr-reviews.approve', $review), ['approved_content' => 'Must not persist'])
            ->assertNotFound();

        $review->refresh();
        $this->assertSame(OcrReview::STATUS_PENDING, $review->status);
        $this->assertNull($review->approved_content);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_approve_and_reject_require_change_permission_including_for_archibot_admin(): void
    {
        $owner = User::factory()->create();
        $approveReview = $this->review($owner, 456, 'Approve original retained', 'Approve correction retained');
        $rejectReview = $this->review($owner, 789, 'Reject original retained', 'Reject correction retained');
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        Http::fake([
            'paperless.example/api/documents/456/' => Http::response([], 200, ['Allow' => 'GET, OPTIONS']),
            'paperless.example/api/documents/789/' => Http::response([], 200, ['Allow' => 'GET, OPTIONS']),
        ]);

        $this->actingAs($admin)
            ->post(route('ocr-reviews.approve', $approveReview), ['approved_content' => 'Must not persist'])
            ->assertNotFound();
        $this->actingAs($admin)->post(route('ocr-reviews.reject', $rejectReview))->assertNotFound();
        $this->assertSame(OcrReview::STATUS_PENDING, $approveReview->refresh()->status);
        $this->assertNull($approveReview->approved_content);
        $this->assertSame(OcrReview::STATUS_PENDING, $rejectReview->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertNoPaperlessContentPatchWasSent();
    }

    public function test_denied_mutations_check_permission_before_loading_local_ocr_content(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create(['paperless_token' => 'denied-token']);
        $approveReview = $this->review($owner, 456, 'Never-loaded approve original', 'Never-loaded approve correction');
        $rejectReview = $this->review($owner, 789, 'Never-loaded reject original', 'Never-loaded reject correction');
        $queriesBeforePermission = [];
        DB::listen(function ($query) use (&$queriesBeforePermission): void {
            $queriesBeforePermission[] = strtolower($query->sql);
        });
        Http::fake(function () use (&$queriesBeforePermission) {
            $ocrQueries = collect($queriesBeforePermission)->filter(
                fn (string $sql): bool => str_contains($sql, 'from "ocr_reviews"')
                    || str_contains($sql, 'from `ocr_reviews`')
            );
            $this->assertTrue($ocrQueries->isNotEmpty());
            $this->assertFalse($ocrQueries->contains(
                fn (string $sql): bool => str_contains($sql, 'original_content')
                    || str_contains($sql, 'ocr_content')
                    || str_contains($sql, 'approved_content')
            ));

            return Http::response([], 403);
        });

        $this->actingAs($user)
            ->post(route('ocr-reviews.approve', $approveReview), ['approved_content' => 'Must not persist'])
            ->assertNotFound();
        $this->actingAs($user)->post(route('ocr-reviews.reject', $rejectReview))->assertNotFound();
        $this->assertSame(OcrReview::STATUS_PENDING, $approveReview->newQuery()->whereKey($approveReview->id)->value('status'));
        $this->assertSame(OcrReview::STATUS_PENDING, $rejectReview->newQuery()->whereKey($rejectReview->id)->value('status'));
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertNoPaperlessContentPatchWasSent();
    }

    public function test_approve_and_reject_recheck_change_permission_immediately_before_mutation(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create(['paperless_token' => 'reviewer-token']);
        $approveReview = $this->review($owner, 456, 'Approve original retained', 'Approve correction retained');
        $rejectReview = $this->review($owner, 789, 'Reject original retained', 'Reject correction retained');
        Http::fake([
            'paperless.example/api/documents/456/' => Http::sequence()
                ->push([], 200, ['Allow' => 'GET, PATCH, OPTIONS'])
                ->push([], 403),
            'paperless.example/api/documents/789/' => Http::sequence()
                ->push([], 200, ['Allow' => 'GET, PATCH, OPTIONS'])
                ->push([], 403),
        ]);

        $this->actingAs($user)
            ->post(route('ocr-reviews.approve', $approveReview), ['approved_content' => 'Must not persist'])
            ->assertNotFound();
        $this->actingAs($user)->post(route('ocr-reviews.reject', $rejectReview))->assertNotFound();

        $this->assertSame(OcrReview::STATUS_PENDING, $approveReview->refresh()->status);
        $this->assertNull($approveReview->approved_content);
        $this->assertSame(OcrReview::STATUS_PENDING, $rejectReview->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
        Http::assertSentCount(4);
        $this->assertNoPaperlessContentPatchWasSent();
    }

    public function test_paperless_api_failures_fail_closed_for_show_store_approve_and_reject(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create(['paperless_token' => 'error-token']);
        $showReview = $this->review($owner, 111, 'API-error show original', 'API-error show correction');
        $approveReview = $this->review($owner, 222, 'API-error approve original', 'API-error approve correction');
        $rejectReview = $this->review($owner, 333, 'API-error reject original', 'API-error reject correction');
        Http::fake(fn () => Http::response([], 500));

        $this->actingAs($user)->get(route('ocr-reviews.show', $showReview))->assertNotFound();
        $this->actingAs($user)->post(route('ocr-reviews.store'), [
            'paperless_document_id' => 444,
            'ocr_content' => 'API-error store correction',
        ])->assertForbidden();
        $this->actingAs($user)
            ->post(route('ocr-reviews.approve', $approveReview), ['approved_content' => 'Must not persist'])
            ->assertNotFound();
        $this->actingAs($user)->post(route('ocr-reviews.reject', $rejectReview))->assertNotFound();

        $this->assertDatabaseCount('ocr_reviews', 3);
        $this->assertSame(OcrReview::STATUS_PENDING, $approveReview->refresh()->status);
        $this->assertNull($approveReview->approved_content);
        $this->assertSame(OcrReview::STATUS_PENDING, $rejectReview->refresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertNoPaperlessContentPatchWasSent();
    }

    public function test_store_denies_archibot_admin_without_live_change_permission(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'paperless_token' => 'admin-token']);
        Http::fake([
            'paperless.example/api/documents/456/' => Http::response([], 200, ['Allow' => 'GET, OPTIONS']),
        ]);

        $this->actingAs($admin)->post(route('ocr-reviews.store'), [
            'paperless_document_id' => 456,
            'ocr_content' => 'Admin-denied correction',
        ])->assertForbidden();

        $this->assertDatabaseCount('ocr_reviews', 0);
        Http::assertSentCount(1);
        $this->assertNoPaperlessContentPatchWasSent();
    }

    public function test_reject_with_change_permission_keeps_snapshots_and_sends_no_patch(): void
    {
        $owner = User::factory()->create();
        $user = User::factory()->create(['paperless_token' => 'reviewer-token']);
        $review = $this->review($owner, 456, 'Original retained', 'Correction retained');
        Http::fake([
            'paperless.example/api/documents/456/' => Http::response([], 200, ['Allow' => 'GET, PATCH, OPTIONS']),
        ]);

        $this->actingAs($user)->post(route('ocr-reviews.reject', $review))->assertRedirect(route('ocr-reviews.index'));
        $review->refresh();
        $this->assertSame(OcrReview::STATUS_REJECTED, $review->status);
        $this->assertSame('Original retained', $review->original_content);
        $this->assertSame('Correction retained', $review->ocr_content);
        $this->assertNoPaperlessContentPatchWasSent();
    }

    public function test_stats_does_not_expose_unscoped_ocr_counts_or_content(): void
    {
        $user = User::factory()->create(['paperless_token' => 'reader-token']);
        $this->review($user, 456, 'Stats-hidden original marker', 'Stats-hidden correction marker');

        $this->actingAs($user)
            ->get(route('stats.index'))
            ->assertOk()
            ->assertDontSee('Stats-hidden original marker')
            ->assertDontSee('Stats-hidden correction marker')
            ->assertInertia(fn (Assert $page) => $page->missing('ocrReviewStatusCounts'));
    }

    public function test_restore_route_and_auto_write_setting_are_not_exposed_or_exported(): void
    {
        $user = User::factory()->create(['paperless_token' => 'reviewer-token']);
        $review = $this->review($user, 456, 'Historical original', 'Historical correction', OcrReview::STATUS_WRITTEN_BACK);
        AppSetting::put('ocr.auto_write_back', '1');

        $this->actingAs($user)->post("/ocr-reviews/{$review->id}/restore")->assertNotFound();
        $this->assertArrayNotHasKey('ocr.auto_write_back', config('archibot_settings.definitions'));
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)
            ->get(route('admin.settings.edit', ['section' => 'ocr']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where(
                'groups',
                fn ($groups): bool => collect($groups)
                    ->flatMap(fn (array $group) => $group['settings'])
                    ->doesntContain(fn (array $setting): bool => $setting['key'] === 'ocr.auto_write_back')
            ));

        $path = config('archibot_settings.import_paths')[0];
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "OCR_AUTO_WRITE_BACK=from-existing-file\n");
        app(PythonRuntimeConfigExporter::class)->export([
            'OCR_AUTO_WRITE_BACK' => 'from-explicit-override',
        ]);
        $this->assertStringNotContainsString('OCR_AUTO_WRITE_BACK', File::get($path));

        $review->refresh();
        $this->assertSame('Historical original', $review->original_content);
        $this->assertSame('Historical correction', $review->ocr_content);
        $this->assertSame(OcrReview::STATUS_WRITTEN_BACK, $review->status);
    }

    private function review(
        User $creator,
        int $documentId,
        string $original,
        string $corrected,
        string $status = OcrReview::STATUS_PENDING,
    ): OcrReview {
        return OcrReview::query()->create([
            'paperless_document_id' => $documentId,
            'original_content' => $original,
            'ocr_content' => $corrected,
            'status' => $status,
            'created_by_user_id' => $creator->id,
        ]);
    }

    private function assertNoPaperlessContentPatchWasSent(): void
    {
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'PATCH'
            && array_key_exists('content', $request->data()));
    }
}
