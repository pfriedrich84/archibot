<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type',
    'status',
    'payload',
    'input_path',
    'output_path',
    'result',
    'exit_code',
    'error',
    'created_by_user_id',
    'started_at',
    'finished_at',
])]
class WorkerJob extends Model
{
    use HasFactory;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const TYPE_POLL = 'poll';

    public const TYPE_REINDEX = 'reindex';

    public const TYPE_PROCESS_DOCUMENT = 'process_document';

    public const TYPE_COMMIT_REVIEW = 'commit_review';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_POLL,
            self::TYPE_REINDEX,
            self::TYPE_PROCESS_DOCUMENT,
            self::TYPE_COMMIT_REVIEW,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function userQueueableTypes(): array
    {
        return [self::TYPE_POLL, self::TYPE_REINDEX, self::TYPE_PROCESS_DOCUMENT];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewSuggestions(): HasMany
    {
        return $this->hasMany(ReviewSuggestion::class);
    }
}
