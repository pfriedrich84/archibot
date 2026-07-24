<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'status', 'embedding_model', 'dimensions', 'content_scope', 'scope', 'release_threshold',
    'release_target_population', 'released_at', 'release_status', 'started_at', 'completed_at',
    'document_count', 'embedded_count', 'failed_count', 'error',
])]
class EmbeddingIndexState extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_BUILDING = 'building';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    public const STATUS_STALE = 'stale';

    public const RELEASE_STATUS_PENDING = 'pending';

    public const RELEASE_STATUS_BLOCKED = 'blocked';

    public const RELEASE_STATUS_RELEASED = 'released';

    protected $table = 'embedding_index_state';

    protected function casts(): array
    {
        return [
            'release_threshold' => 'integer',
            'release_target_population' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }
}
