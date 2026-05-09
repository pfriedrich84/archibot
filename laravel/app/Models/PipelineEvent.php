<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'pipeline_run_id',
    'webhook_delivery_id',
    'command_id',
    'event_type',
    'paperless_document_id',
    'level',
    'message',
    'payload',
    'created_at',
])]
class PipelineEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class);
    }

    public function webhookDelivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class);
    }
}
