<?php

namespace Tests\Feature\Review;

use App\Services\Paperless\PaperlessClient;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaperlessReviewedMutationTest extends TestCase
{
    public function test_allowed_metadata_patch_is_dispatched(): void
    {
        Http::fake(['paperless.test/api/documents/42/' => Http::response([], 200)]);

        app(PaperlessClient::class)->patchDocument('reviewer-token', 42, [
            'title' => 'Reviewed',
            'created_date' => '2026-07-18',
            'correspondent' => 3,
            'document_type' => 4,
            'tags' => [5],
        ]);

        Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/api/documents/42/')
            && $request['title'] === 'Reviewed');
    }

    #[DataProvider('prohibitedFields')]
    public function test_ocr_content_file_and_version_fields_are_rejected_before_dispatch(string $field): void
    {
        Http::fake();

        try {
            app(PaperlessClient::class)->patchReviewedDocument('reviewer-token', 42, [$field => 'x']);
            $this->fail("{$field} was not rejected.");
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('prohibited fields', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function prohibitedFields(): array
    {
        return [
            'ocr' => ['ocr'],
            'content' => ['content'],
            'file' => ['file'],
            'files' => ['files'],
            'version' => ['version'],
            'versions' => ['versions'],
        ];
    }

    #[DataProvider('invalidStoragePathAssignments')]
    public function test_storage_path_requires_a_positive_integer_before_dispatch(mixed $value): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive ID');

        try {
            app(PaperlessClient::class)->patchReviewedDocument(
                'reviewer-token',
                42,
                ['storage_path' => $value],
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    /** @return array<string, array{mixed}> */
    public static function invalidStoragePathAssignments(): array
    {
        return [
            'null' => [null],
            'zero' => [0],
            'negative' => [-1],
            'numeric string' => ['7'],
            'boolean' => [true],
        ];
    }

    public function test_missing_live_storage_path_fails_closed_before_patch_dispatch(): void
    {
        Http::fake(['paperless.test/api/documents/42/' => Http::response([
            'id' => 42,
            'title' => 'Document',
        ], 200)]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must report a null storage path');

        try {
            app(PaperlessClient::class)->patchReviewedDocument(
                'reviewer-token',
                42,
                ['storage_path' => 7],
            );
        } finally {
            Http::assertSentCount(1);
            Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH');
        }
    }

    public function test_existing_storage_path_is_immutable_before_patch_dispatch(): void
    {
        Http::fake(['paperless.test/api/documents/42/' => Http::response([
            'id' => 42,
            'title' => 'Document',
            'storage_path' => 9,
        ], 200)]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('immutable');

        try {
            app(PaperlessClient::class)->patchReviewedDocument(
                'reviewer-token',
                42,
                ['storage_path' => 7],
            );
        } finally {
            Http::assertSentCount(1);
            Http::assertNotSent(fn ($request): bool => $request->method() === 'PATCH');
        }
    }

    public function test_absent_storage_path_is_set_through_authorized_manual_review_seam(): void
    {
        Http::fakeSequence('paperless.test/api/documents/42/')
            ->push(['id' => 42, 'title' => 'Document', 'storage_path' => null], 200)
            ->push([], 200);

        app(PaperlessClient::class)->patchReviewedDocument(
            'reviewer-token',
            42,
            ['storage_path' => 7],
        );

        $requests = Http::recorded();
        $this->assertCount(2, $requests);
        $this->assertSame('GET', $requests[0][0]->method());
        $this->assertSame('PATCH', $requests[1][0]->method());
        $this->assertSame(7, $requests[1][0]['storage_path']);
        $this->assertSame('Token reviewer-token', $requests[1][0]->header('Authorization')[0]);
    }
}
