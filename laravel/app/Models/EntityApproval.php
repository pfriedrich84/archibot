<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'name', 'status', 'paperless_id', 'source_review_suggestion_id', 'reviewed_by_user_id', 'reviewed_at', 'sync_status', 'sync_worker_job_id'])]
class EntityApproval extends Model
{
    use HasFactory;

    public const TYPE_TAG = 'tag';

    public const TYPE_CORRESPONDENT = 'correspondent';

    public const TYPE_DOCUMENT_TYPE = 'document_type';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const SYNC_STATUS_QUEUED = 'queued';

    public const SYNC_STATUS_SYNCED = 'synced';

    public const SYNC_STATUS_FAILED = 'failed';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function sourceReviewSuggestion(): BelongsTo
    {
        return $this->belongsTo(ReviewSuggestion::class, 'source_review_suggestion_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function syncWorkerJob(): BelongsTo
    {
        return $this->belongsTo(WorkerJob::class, 'sync_worker_job_id');
    }

    public function mark(string $status, User $user, ?int $paperlessId = null): void
    {
        $this->forceFill([
            'status' => $status,
            'paperless_id' => $paperlessId ?? $this->paperless_id,
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
        ])->save();
    }
}
