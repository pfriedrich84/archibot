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
    protected $table = 'embedding_index_state';

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
