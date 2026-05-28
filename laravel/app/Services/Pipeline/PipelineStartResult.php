<?php

namespace App\Services\Pipeline;

use App\Models\PipelineRun;

class PipelineStartResult
{
    public function __construct(
        public readonly PipelineRun $pipelineRun,
        public readonly string $outcome,
        public readonly string $dedupeKey,
        public readonly ?string $blockedReason = null,
        public readonly bool $created = true,
    ) {}
}
