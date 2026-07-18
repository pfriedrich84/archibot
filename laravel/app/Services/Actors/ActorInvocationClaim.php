<?php

namespace App\Services\Actors;

final readonly class ActorInvocationClaim
{
    public function __construct(
        public string $token,
        public int $sourceVersion,
        public int $actorExecutionId,
        public int $attempt,
    ) {}
}
