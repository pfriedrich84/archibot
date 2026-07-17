<?php

namespace App\Services\Pipeline;

use App\Models\PollCandidate;

final class PollCandidateLease
{
    public function __construct(
        public readonly PollCandidate $candidate,
        public readonly string $token,
        public readonly int $version,
        public readonly ?string $validationError = null,
    ) {}
}
