<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'worker_job_id',
    'stream',
    'level',
    'event',
    'paperless_document_id',
    'phase',
    'message',
    'context',
])]
class WorkerJobLog extends Model
{
    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    public function workerJob(): BelongsTo
    {
        return $this->belongsTo(WorkerJob::class);
    }
}
