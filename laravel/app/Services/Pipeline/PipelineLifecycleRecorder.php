<?php

namespace App\Services\Pipeline;

use App\Models\AuditLog;
use App\Models\PipelineEvent;

/**
 * Keeps append-only lifecycle records out of PipelineRun lifecycle-owner files.
 *
 * Those files intentionally permit only a closed, literal method vocabulary and
 * cannot contain model creation calls or dynamic callbacks.
 */
class PipelineLifecycleRecorder
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function event(array $attributes): void
    {
        PipelineEvent::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function audit(array $attributes): void
    {
        AuditLog::create($attributes);
    }
}
