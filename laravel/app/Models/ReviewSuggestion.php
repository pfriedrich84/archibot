<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'source_suggestion_id',
    'dedupe_key',
    'pipeline_run_id',
    'paperless_document_id',
    'paperless_version_id',
    'paperless_version_checksum',
    'status',
    'confidence',
    'reasoning',
    'original_title',
    'original_date',
    'original_correspondent_id',
    'original_document_type_id',
    'original_storage_path_id',
    'original_tags',
    'proposed_title',
    'proposed_date',
    'proposed_correspondent_name',
    'proposed_correspondent_id',
    'proposed_document_type_name',
    'proposed_document_type_id',
    'proposed_storage_path_name',
    'proposed_storage_path_id',
    'proposed_tags',
    'context_documents',
    'raw_response',
    'judge_verdict',
    'judge_reasoning',
    'original_proposed_snapshot',
    'created_by_user_id',
    'reviewed_by_user_id',
    'reviewed_at',
    'commit_status',
    'commit_command_id',
    'staleness_reason',
    'origin',
    'context_quality',
    'context_document_count',
    'requested_by_user_id',
    'request_source',
])]
class ReviewSuggestion extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_STALE = 'stale';

    public const COMMIT_STATUS_QUEUED = 'queued';

    public const COMMIT_STATUS_COMMITTED = 'committed';

    public const COMMIT_STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'original_date' => 'date',
            'proposed_date' => 'date',
            'paperless_version_id' => 'integer',
            'context_document_count' => 'integer',
            'original_tags' => 'array',
            'proposed_tags' => 'array',
            'context_documents' => 'array',
            'raw_response' => 'array',
            'original_proposed_snapshot' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Query for the latest review suggestion per Paperless document.
     *
     * Keep review-page and dashboard counting logic on this single shared base
     * query so both screens report the same user-facing queue size.
     *
     * @return Builder<ReviewSuggestion>
     */
    public static function latestPerDocumentQuery(): Builder
    {
        return self::query()->whereIn('id', self::query()
            ->selectRaw('MAX(id)')
            ->groupBy('paperless_document_id'));
    }

    /** @return Builder<ReviewSuggestion> */
    public static function pendingReviewQueueQuery(): Builder
    {
        return self::latestPerDocumentQuery()
            ->where('status', self::STATUS_PENDING);
    }

    public static function pendingReviewQueueCount(): int
    {
        return self::pendingReviewQueueQuery()->count();
    }

    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class);
    }

    public function commitCommand(): BelongsTo
    {
        return $this->belongsTo(Command::class, 'commit_command_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function markReviewed(string $status, User $user): void
    {
        $this->forceFill([
            'status' => $status,
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
        ])->save();
    }

    public function markStale(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_STALE,
            'staleness_reason' => $reason,
        ])->save();
    }
}
