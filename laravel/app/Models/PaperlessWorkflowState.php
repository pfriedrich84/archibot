<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workflow_key', 'paperless_workflow_id', 'status', 'auto_managed', 'enabled_desired', 'drift_detected', 'desired_definition', 'remote_definition', 'drift_fields', 'last_error', 'last_synced_at', 'last_remote_read_at'])]
class PaperlessWorkflowState extends Model
{
    protected function casts(): array
    {
        return [
            'auto_managed' => 'boolean',
            'enabled_desired' => 'boolean',
            'drift_detected' => 'boolean',
            'desired_definition' => 'array',
            'remote_definition' => 'array',
            'drift_fields' => 'array',
            'last_synced_at' => 'datetime',
            'last_remote_read_at' => 'datetime',
        ];
    }
}
