<?php

namespace App\Services\Actors;

use DateTimeImmutable;
use RuntimeException;

final readonly class PythonActorOutcome
{
    public const PROTOCOL = 'archibot.actor-outcome';

    public const VERSION = 1;

    /** @var array<int, string> */
    private const STATUSES = [
        'succeeded', 'skipped', 'blocked', 'cancelled', 'retrying',
        'failed-permanent', 'protocol-failure',
    ];

    public function __construct(
        public string $status,
        public string $actor,
        public string $sourceKind,
        public int $sourceId,
        public ?int $actorExecutionId,
        public ?int $attempt,
        public ?string $retryAt,
        public ?string $errorType,
    ) {}

    public static function fromProcessOutput(string $output): self
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        $protocolPayloads = [];
        $lastNonEmpty = null;
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $lastNonEmpty = $index;
            $payload = json_decode(trim($line), true);
            if (is_array($payload) && ($payload['protocol'] ?? null) === self::PROTOCOL) {
                $protocolPayloads[$index] = $payload;
            }
        }
        if (count($protocolPayloads) !== 1) {
            throw new RuntimeException(count($protocolPayloads) === 0
                ? 'Python actor outcome protocol record is missing.'
                : 'Python actor emitted more than one outcome protocol record.');
        }
        $index = array_key_first($protocolPayloads);
        if ($index !== $lastNonEmpty) {
            throw new RuntimeException('Python actor outcome protocol record must be the final output record.');
        }

        return self::fromPayload($protocolPayloads[$index]);
    }

    /** @param array<string, mixed> $payload */
    private static function fromPayload(array $payload): self
    {
        $required = ['protocol', 'version', 'status', 'actor', 'source', 'actor_execution_id', 'attempt', 'retry_at', 'error_type'];
        $keys = array_keys($payload);
        sort($keys);
        $expectedKeys = $required;
        sort($expectedKeys);
        if ($keys !== $expectedKeys) {
            throw new RuntimeException('Python actor outcome protocol record must contain exactly the required fields.');
        }
        if (($payload['version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('Python actor outcome protocol version is unsupported.');
        }
        $status = $payload['status'] ?? null;
        $actor = $payload['actor'] ?? null;
        $source = $payload['source'] ?? null;
        if (! is_string($status) || ! in_array($status, self::STATUSES, true)
            || ! is_string($actor) || $actor === ''
            || ! is_array($source) || count($source) !== 2
            || ! array_key_exists('kind', $source) || ! array_key_exists('id', $source)
            || ! in_array($source['kind'] ?? null, ['pipeline_run', 'command', 'webhook_delivery'], true)
            || ! is_int($source['id'] ?? null) || $source['id'] <= 0) {
            throw new RuntimeException('Python actor outcome protocol record is malformed.');
        }

        $executionId = $payload['actor_execution_id'] ?? null;
        $attempt = $payload['attempt'] ?? null;
        $retryAt = $payload['retry_at'] ?? null;
        $errorType = $payload['error_type'] ?? null;
        if ($status === 'protocol-failure') {
            if ($executionId !== null || $attempt !== null || $retryAt !== null
                || ! is_string($errorType) || trim($errorType) === '') {
                throw new RuntimeException('Python protocol-failure outcome has contradictory fields.');
            }
        } else {
            if (! is_int($executionId) || $executionId <= 0 || ! is_int($attempt) || $attempt <= 0) {
                throw new RuntimeException('Python domain outcome requires a durable execution and attempt.');
            }
            if ($status === 'retrying') {
                if (! is_string($retryAt) || ! self::validTimestamp($retryAt)
                    || ! is_string($errorType) || trim($errorType) === '') {
                    throw new RuntimeException('Python retrying outcome requires retry_at and error_type.');
                }
            } elseif ($retryAt !== null) {
                throw new RuntimeException('Python non-retrying outcome must not contain retry_at.');
            }
            if ($status === 'succeeded' && $errorType !== null) {
                throw new RuntimeException('Python succeeded outcome must not contain error_type.');
            }
            if (in_array($status, ['skipped', 'blocked', 'cancelled', 'failed-permanent'], true)
                && (! is_string($errorType) || trim($errorType) === '')) {
                throw new RuntimeException('Python non-success outcome requires error_type.');
            }
        }
        if ($errorType !== null && ! is_string($errorType)) {
            throw new RuntimeException('Python actor outcome protocol record is malformed.');
        }

        return new self($status, $actor, $source['kind'], $source['id'], $executionId, $attempt, $retryAt, $errorType);
    }

    private static function validTimestamp(string $value): bool
    {
        try {
            new DateTimeImmutable($value);

            return trim($value) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    public function assertInvocation(string $actor, string $kind, int $id): void
    {
        if ($this->actor !== $actor) {
            throw new RuntimeException('Python actor outcome actor does not match the dispatched job.');
        }
        if ($this->sourceKind !== $kind || $this->sourceId !== $id) {
            throw new RuntimeException('Python actor outcome durable source does not match the dispatched job.');
        }
    }

    public function assertSource(string $kind, int $id): void
    {
        $this->assertInvocation($this->actor, $kind, $id);
    }
}
