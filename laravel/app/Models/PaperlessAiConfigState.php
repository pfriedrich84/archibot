<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['desired_config', 'remote_config', 'drift_fields', 'sync_status', 'last_sync_error', 'last_synced_at', 'last_remote_read_at'])]
class PaperlessAiConfigState extends Model
{
    protected function casts(): array
    {
        return [
            'desired_config' => 'array',
            'remote_config' => 'array',
            'drift_fields' => 'array',
            'last_synced_at' => 'datetime',
            'last_remote_read_at' => 'datetime',
        ];
    }
}
