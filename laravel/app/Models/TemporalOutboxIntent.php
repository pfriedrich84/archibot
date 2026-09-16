<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'intent_key',
    'operation',
    'workflow_id',
    'workflow_type',
    'task_queue',
    'signal_name',
    'payload',
    'status',
    'attempts',
    'available_at',
    'locked_at',
    'delivered_at',
    'last_error',
])]
class TemporalOutboxIntent extends Model
{
    public const OPERATION_START = 'start_workflow';

    public const OPERATION_SIGNAL = 'signal_workflow';

    public const OPERATION_SIGNAL_WITH_START = 'signal_with_start';

    public const OPERATION_CANCEL = 'cancel_workflow';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERING = 'delivering';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'locked_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
