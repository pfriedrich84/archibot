<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'status', 'embedding_model', 'dimensions', 'content_scope', 'started_at', 'completed_at',
    'document_count', 'embedded_count', 'failed_count', 'error',
])]
class EmbeddingIndexState extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_BUILDING = 'building';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    public const STATUS_STALE = 'stale';

    protected $table = 'embedding_index_state';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
