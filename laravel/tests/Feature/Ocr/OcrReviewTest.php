<?php

namespace Tests\Feature\Ocr;

use App\Models\AppSetting;
use App\Models\OcrReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OcrReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_ocr_review_fetches_and_preserves_current_paperless_content(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake([
            'paperless.example/api/documents/123/' => Http::response([
                'id' => 123,
                'content' => 'Current Paperless OCR text',
            ], 200),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);

        $response = $this->actingAs($user)->post(route('ocr-reviews.store'), [
            'paperless_document_id' => 123,
            'ocr_content' => 'New OCR text',
        ]);

        $review = OcrReview::query()->firstOrFail();
        $response->assertRedirect(route('ocr-reviews.show', $review));
        $this->assertSame('Current Paperless OCR text', $review->original_content);
        $this->assertSame('New OCR text', $review->ocr_content);
        $this->assertSame(OcrReview::STATUS_PENDING, $review->status);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $user->id,
            'event' => 'ocr_review.created',
            'target_type' => 'ocr_review',
            'target_id' => (string) $review->id,
        ]);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Token user-token'));
    }

    public function test_index_exposes_auto_write_warning_state(): void
    {
        AppSetting::put('ocr.auto_write_back', '1');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('ocr-reviews.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ocr/Index')
                ->where('autoWriteBackEnabled', true)
            );
    }

    public function test_review_detail_shows_original_and_ocr_content_for_diff_review(): void
    {
        $user = User::factory()->create();
        $review = OcrReview::query()->create([
            'paperless_document_id' => 456,
            'original_content' => 'old text',
            'ocr_content' => 'new text',
            'status' => OcrReview::STATUS_PENDING,
            'created_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('ocr-reviews.show', $review))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ocr/Show')
                ->where('review.paperless_document_id', 456)
                ->where('review.original_content', 'old text')
                ->where('review.ocr_content', 'new text')
            );
    }

    public function test_approving_ocr_review_writes_edited_content_back_to_paperless(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake([
            'paperless.example/api/documents/123/' => Http::response(['id' => 123], 200),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);
        $review = OcrReview::query()->create([
            'paperless_document_id' => 123,
            'original_content' => 'old text',
            'ocr_content' => 'generated text',
            'status' => OcrReview::STATUS_PENDING,
            'created_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('ocr-reviews.approve', $review), [
                'approved_content' => 'edited approved text',
            ])
            ->assertRedirect(route('ocr-reviews.show', $review));

        $review->refresh();
        $this->assertSame(OcrReview::STATUS_WRITTEN_BACK, $review->status);
        $this->assertSame('edited approved text', $review->approved_content);
        $this->assertSame($user->id, $review->reviewed_by_user_id);
        $this->assertNotNull($review->written_back_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'ocr_review.written_back',
            'target_id' => (string) $review->id,
        ]);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->url() === 'https://paperless.example/api/documents/123/'
            && $request['content'] === 'edited approved text');
    }

    public function test_global_auto_write_mode_preserves_original_and_writes_generated_ocr_text(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        AppSetting::put('ocr.auto_write_back', '1');
        Http::fake([
            'paperless.example/api/documents/123/' => Http::sequence()
                ->push(['id' => 123, 'content' => 'old text'], 200)
                ->push(['id' => 123], 200),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);

        $this->actingAs($user)->post(route('ocr-reviews.store'), [
            'paperless_document_id' => 123,
            'ocr_content' => 'auto generated OCR text',
        ]);

        $review = OcrReview::query()->firstOrFail();
        $this->assertSame('old text', $review->original_content);
        $this->assertSame('auto generated OCR text', $review->approved_content);
        $this->assertSame(OcrReview::STATUS_WRITTEN_BACK, $review->status);
        $this->assertNotNull($review->written_back_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'ocr_review.auto_write_requested',
            'target_id' => (string) $review->id,
        ]);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request['content'] === 'auto generated OCR text');
    }

    public function test_failed_write_back_keeps_approved_content_for_retry(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake([
            'paperless.example/api/documents/123/' => Http::response([], 403),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);
        $review = OcrReview::query()->create([
            'paperless_document_id' => 123,
            'original_content' => 'old text',
            'ocr_content' => 'generated text',
            'status' => OcrReview::STATUS_PENDING,
            'created_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('ocr-reviews.approve', $review), [
                'approved_content' => 'retry me later',
            ])
            ->assertRedirect(route('ocr-reviews.show', $review));

        $review->refresh();
        $this->assertSame(OcrReview::STATUS_WRITE_BACK_FAILED, $review->status);
        $this->assertSame('retry me later', $review->approved_content);
        $this->assertSame('Could not update Paperless document content.', $review->write_back_error);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'ocr_review.write_back_failed',
            'target_id' => (string) $review->id,
        ]);
    }

    public function test_restore_writes_preserved_original_content_back_to_paperless(): void
    {
        AppSetting::put('paperless.url', 'https://paperless.example');
        Http::fake([
            'paperless.example/api/documents/123/' => Http::response(['id' => 123], 200),
        ]);

        $user = User::factory()->create(['paperless_token' => 'user-token']);
        $review = OcrReview::query()->create([
            'paperless_document_id' => 123,
            'original_content' => 'restore this original text',
            'ocr_content' => 'generated text',
            'approved_content' => 'approved text',
            'status' => OcrReview::STATUS_WRITTEN_BACK,
            'created_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('ocr-reviews.restore', $review))
            ->assertRedirect(route('ocr-reviews.show', $review));

        $review->refresh();
        $this->assertSame(OcrReview::STATUS_RESTORED, $review->status);
        $this->assertNotNull($review->restored_at);
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request['content'] === 'restore this original text');
    }

    public function test_rejecting_pending_ocr_review_discards_the_result(): void
    {
        $user = User::factory()->create();
        $review = OcrReview::query()->create([
            'paperless_document_id' => 456,
            'original_content' => 'old text',
            'ocr_content' => 'new text',
            'status' => OcrReview::STATUS_PENDING,
            'created_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('ocr-reviews.reject', $review))
            ->assertRedirect(route('ocr-reviews.index'));

        $this->assertSame(OcrReview::STATUS_REJECTED, $review->refresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'ocr_review.rejected',
            'target_id' => (string) $review->id,
        ]);
    }
}
