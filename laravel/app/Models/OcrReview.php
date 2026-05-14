<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'paperless_document_id',
    'dedupe_key',
    'original_content',
    'ocr_content',
    'approved_content',
    'status',
    'write_back_error',
    'created_by_user_id',
    'reviewed_by_user_id',
    'reviewed_at',
    'written_back_at',
    'restored_at',
])]
class OcrReview extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WRITTEN_BACK = 'written_back';

    public const STATUS_WRITE_BACK_FAILED = 'write_back_failed';

    public const STATUS_RESTORED = 'restored';

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'written_back_at' => 'datetime',
            'restored_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
