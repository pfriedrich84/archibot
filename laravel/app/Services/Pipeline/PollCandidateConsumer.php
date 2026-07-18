<?php

namespace App\Services\Pipeline;

use App\Models\PipelineEvent;
use App\Models\PollCandidate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

class PollCandidateConsumer
{
    private const LEASE_MINUTES = 5;

    public function __construct(
        private readonly DocumentPipelineStarter $starter,
        private readonly PipelineContentStateNormalizer $normalizer,
    ) {}

    /** @return array{completed: int, skipped: int, failed: int} */
    public function consumeCommand(int $commandId, int $limit = 1000): array
    {
        return $this->consumeQuery(
            PollCandidate::query()->where('command_id', $commandId),
            $limit,
        );
    }

    /** @return array{completed: int, skipped: int, failed: int} */
    public function replayPending(int $limit = 100): array
    {
        return $this->consumeQuery(PollCandidate::query(), $limit);
    }

    /**
     * Claim a ready or expired candidate with a UUID plus monotonic version.
     * Public so recovery coordinators can use the same fenced protocol.
     */
    public function claimCandidate(int $id): ?PollCandidateLease
    {
        return DB::transaction(function () use ($id): ?PollCandidateLease {
            $candidate = PollCandidate::query()->lockForUpdate()->find($id);
            if ($candidate === null || ! in_array($candidate->status, [PollCandidate::STATUS_READY, PollCandidate::STATUS_CLAIMED], true)) {
                return null;
            }
            if ($candidate->status === PollCandidate::STATUS_CLAIMED
                && $candidate->claimed_at?->isAfter(now()->subMinutes(self::LEASE_MINUTES))) {
                return null;
            }

            $token = (string) Str::uuid();
            $version = $candidate->claim_version + 1;
            $validationError = $this->protocolError($candidate, $candidate->trigger_metadata ?? []);
            $normalizedModified = null;
            $contentHash = null;
            $normalizedContentState = null;

            if ($validationError === null) {
                try {
                    $normalizedModified = $this->normalizer->modified($candidate->discovered_modified);
                    $contentHash = $this->normalizer->contentHash($candidate->content_hash);
                    $normalizedContentState = $this->normalizer->state(
                        $candidate->paperless_document_id,
                        $normalizedModified,
                        $contentHash,
                    );
                } catch (InvalidArgumentException) {
                    $validationError = 'invalid_content_state';
                }
            }

            $candidate->forceFill([
                'status' => PollCandidate::STATUS_CLAIMED,
                'claim_attempts' => $candidate->claim_attempts + 1,
                'claim_version' => $version,
                'claim_token' => $token,
                'claimed_at' => now(),
                'normalized_modified' => $normalizedModified,
                'content_hash' => $contentHash,
                'normalized_content_state' => $normalizedContentState,
                'error_type' => null,
            ])->save();

            return new PollCandidateLease($candidate->fresh(), $token, $version, $validationError);
        });
    }

    /** @param Builder<PollCandidate> $query */
    private function consumeQuery($query, int $limit): array
    {
        $counts = ['completed' => 0, 'skipped' => 0, 'failed' => 0];
        $query->where(function ($query): void {
            $query->where('status', PollCandidate::STATUS_READY)
                ->orWhere(function ($query): void {
                    $query->where('status', PollCandidate::STATUS_CLAIMED)
                        ->where('claimed_at', '<=', now()->subMinutes(self::LEASE_MINUTES));
                });
        })
            ->oldest('id')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $id) use (&$counts): void {
                $outcome = $this->consumeOne($id);
                $counts[$outcome]++;
            });

        return $counts;
    }

    private function consumeOne(int $id): string
    {
        $lease = $this->claimCandidate($id);
        if ($lease === null) {
            return 'skipped';
        }

        if ($lease->validationError !== null) {
            return $this->finishWithoutRun($lease, $lease->validationError) ? 'failed' : 'skipped';
        }

        $candidate = $lease->candidate;
        $metadata = $candidate->trigger_metadata ?? [];
        $force = $metadata['force'];
        if ($candidate->marker_disposition === PollCandidate::MARKER_ALREADY_CLASSIFIED && ! $force) {
            $this->skipMarkerCandidate($lease);

            return 'skipped';
        }

        try {
            $result = $this->starter->start(
                triggerSource: 'poll',
                paperlessDocumentId: $candidate->paperless_document_id,
                paperlessModified: $candidate->normalized_modified,
                contentHash: $candidate->content_hash,
                reprocessRequested: $force,
                reprocessReason: $force ? 'forced_poll_reconciliation' : null,
                reprocessMode: $force ? 'poll_force' : null,
                forceNewRun: $force,
                forceToken: $force ? $candidate->candidate_id : null,
                commandId: $candidate->command_id,
            );
        } catch (Throwable $exception) {
            return $this->releaseFailedClaim($lease, $exception::class) ? 'failed' : 'skipped';
        }

        return $this->completeClaim($lease, $result) ? 'completed' : 'skipped';
    }

    private function skipMarkerCandidate(PollCandidateLease $lease): bool
    {
        return DB::transaction(function () use ($lease): bool {
            $updated = $this->claimedQuery($lease)->update([
                'status' => PollCandidate::STATUS_SKIPPED,
                'completed_at' => now(),
                'starter_outcome' => 'marker_skipped',
                'error_type' => null,
                'updated_at' => now(),
            ]);
            if ($updated !== 1) {
                return false;
            }

            $candidate = $lease->candidate;
            PipelineEvent::query()->create([
                'command_id' => $candidate->command_id,
                'event_type' => 'poll.document.skipped_already_classified',
                'paperless_document_id' => $candidate->paperless_document_id,
                'level' => 'info',
                'message' => 'Already classified Inbox Document skipped by Laravel poll consumer.',
                'payload' => ['candidate_id' => $candidate->candidate_id, 'marker' => 'review_suggestion'],
            ]);

            return true;
        });
    }

    private function completeClaim(PollCandidateLease $lease, PipelineStartResult $result): bool
    {
        return $this->claimedQuery($lease)->update([
            'status' => PollCandidate::STATUS_COMPLETED,
            'completed_at' => now(),
            'starter_outcome' => $result->outcome,
            'pipeline_run_id' => $result->pipelineRun->id,
            'error_type' => null,
            'updated_at' => now(),
        ]) === 1;
    }

    private function releaseFailedClaim(PollCandidateLease $lease, string $errorType): bool
    {
        return $this->claimedQuery($lease)->update([
            'status' => PollCandidate::STATUS_READY,
            'claim_token' => null,
            'claimed_at' => null,
            'error_type' => $errorType,
            'updated_at' => now(),
        ]) === 1;
    }

    private function finishWithoutRun(PollCandidateLease $lease, string $errorType): bool
    {
        return $this->claimedQuery($lease)->update([
            'status' => PollCandidate::STATUS_SKIPPED,
            'completed_at' => now(),
            'starter_outcome' => 'protocol_rejected',
            'error_type' => $errorType,
            'updated_at' => now(),
        ]) === 1;
    }

    /** @return Builder<PollCandidate> */
    private function claimedQuery(PollCandidateLease $lease)
    {
        return PollCandidate::query()
            ->whereKey($lease->candidate->id)
            ->where('status', PollCandidate::STATUS_CLAIMED)
            ->where('claim_token', $lease->token)
            ->where('claim_version', $lease->version);
    }

    /** @param array<string, mixed> $metadata */
    private function protocolError(PollCandidate $candidate, array $metadata): ?string
    {
        if ($candidate->protocol_version !== PollCandidate::PROTOCOL_VERSION) {
            return 'unsupported_protocol_version';
        }
        if (! in_array($candidate->marker_disposition, [
            PollCandidate::MARKER_UNCLASSIFIED,
            PollCandidate::MARKER_ALREADY_CLASSIFIED,
        ], true)) {
            return 'invalid_marker_disposition';
        }
        if (($metadata['trigger_source'] ?? null) !== 'poll'
            || ! is_bool($metadata['force'] ?? null)
            || ($metadata['command_id'] ?? null) !== $candidate->command_id) {
            return 'invalid_trigger_metadata';
        }

        return null;
    }
}
