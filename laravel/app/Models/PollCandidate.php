<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'candidate_id', 'protocol_version', 'command_id', 'paperless_document_id',
    'discovered_modified', 'normalized_modified', 'content_hash',
    'normalized_content_state', 'marker_disposition', 'trigger_metadata',
    'idempotency_key', 'status', 'claim_attempts', 'claim_version', 'claim_token',
    'claimed_at', 'completed_at',
    'starter_outcome', 'pipeline_run_id', 'error_type',
])]
class PollCandidate extends Model
{
    public const PROTOCOL_VERSION = 1;

    public const STATUS_READY = 'ready';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    public const MARKER_UNCLASSIFIED = 'unclassified';

    public const MARKER_ALREADY_CLASSIFIED = 'already_classified';

    protected function casts(): array
    {
        return [
            'trigger_metadata' => 'array',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(Command::class);
    }

    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class);
    }
}
