<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'worker_job_id',
    'paperless_document_id',
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
])]
class ReviewSuggestion extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    protected function casts(): array
    {
        return [
            'original_date' => 'date',
            'proposed_date' => 'date',
            'original_tags' => 'array',
            'proposed_tags' => 'array',
            'context_documents' => 'array',
            'raw_response' => 'array',
            'original_proposed_snapshot' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function workerJob(): BelongsTo
    {
        return $this->belongsTo(WorkerJob::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function markReviewed(string $status, User $user): void
    {
        $this->forceFill([
            'status' => $status,
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
        ])->save();
    }
}
