<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source',
    'event_type',
    'paperless_document_id',
    'dedupe_key',
    'payload_hash',
    'raw_payload',
    'normalized_payload',
    'headers',
    'status',
    'request_id',
    'received_at',
    'processed_at',
    'error',
])]
class WebhookDelivery extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_FAILED_PERMANENT = 'failed_permanent';

    public const STATUS_DISMISSED = 'dismissed';

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
            'headers' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
