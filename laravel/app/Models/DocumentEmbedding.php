<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'paperless_document_id', 'content_hash', 'embedding_model', 'dimensions', 'embedding',
])]
class DocumentEmbedding extends Model
{
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
        ];
    }
}
