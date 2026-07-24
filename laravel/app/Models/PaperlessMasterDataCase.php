<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['entity_type', 'normalized_name', 'canonical_name', 'status', 'proposed_names', 'spelling_variants', 'similar_existing_entities', 'occurrence_count', 'first_observed_at', 'last_observed_at', 'reviewed_by_user_id', 'reviewed_at', 'mapped_paperless_id', 'decision_reason', 'sync_status', 'last_sync_error', 'suppressed_until', 'detail_retention_until'])]
class PaperlessMasterDataCase extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const SYNC_STATUS_QUEUED = 'queued';

    public const SYNC_STATUS_SYNCED = 'synced';

    public const SYNC_STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'proposed_names' => 'array',
            'spelling_variants' => 'array',
            'similar_existing_entities' => 'array',
            'first_observed_at' => 'datetime',
            'last_observed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'suppressed_until' => 'datetime',
            'detail_retention_until' => 'datetime',
        ];
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
