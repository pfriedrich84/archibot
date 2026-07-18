<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'status', 'payload', 'created_by_user_id', 'started_at', 'finished_at', 'error', 'next_retry_at', 'lifecycle_version', 'active_actor_token', 'created_at', 'updated_at'])]
class Command extends Model
{
    public const TYPE_EMBEDDING_INDEX_BUILD = 'embedding_index_build';

    public const TYPE_POLL_RECONCILIATION = 'poll_reconciliation';

    public const TYPE_REINDEX = 'reindex';

    public const TYPE_REINDEX_OCR = 'reindex_ocr';

    public const TYPE_REVIEW_COMMIT = 'review_commit';

    public const TYPE_SYNC_ENTITY_APPROVAL = 'sync_entity_approval';

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_FAILED_PERMANENT = 'failed_permanent';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * @return array<int, string>
     */
    public static function activeStatuses(): array
    {
        return [self::STATUS_PENDING, self::STATUS_QUEUED, self::STATUS_RUNNING];
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }
}
