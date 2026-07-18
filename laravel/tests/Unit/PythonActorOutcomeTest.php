<?php

namespace Tests\Unit;

use App\Services\Actors\PythonActorOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PythonActorOutcomeTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function statuses(): array
    {
        return [
            'succeeded' => ['succeeded'],
            'skipped' => ['skipped'],
            'blocked' => ['blocked'],
            'cancelled' => ['cancelled'],
            'retrying' => ['retrying'],
            'failed-permanent' => ['failed-permanent'],
            'protocol-failure' => ['protocol-failure'],
        ];
    }

    #[DataProvider('statuses')]
    public function test_parses_every_version_one_domain_status(string $status): void
    {
        $protocolFailure = $status === 'protocol-failure';
        $outcome = PythonActorOutcome::fromProcessOutput("setup chatter\n".json_encode([
            'protocol' => 'archibot.actor-outcome',
            'version' => 1,
            'status' => $status,
            'actor' => 'test',
            'source' => ['kind' => 'command', 'id' => 42],
            'actor_execution_id' => $protocolFailure ? null : 9,
            'attempt' => $protocolFailure ? null : 2,
            'retry_at' => $status === 'retrying' ? '2026-07-19T12:00:00+00:00' : null,
            'error_type' => $status === 'succeeded' ? null : 'test_error',
        ]));

        $this->assertSame($status, $outcome->status);
        $outcome->assertInvocation('test', 'command', 42);
    }

    /** @return array<string, array{string, string}> */
    public static function protocolFailures(): array
    {
        return [
            'missing' => ['', 'missing'],
            'malformed-json' => ['{broken', 'missing'],
            'version-mismatch' => [
                '{"protocol":"archibot.actor-outcome","version":2,"status":"succeeded","actor":"test","source":{"kind":"command","id":42},"actor_execution_id":9,"attempt":1,"retry_at":null,"error_type":null}',
                'version',
            ],
            'malformed-record' => [
                '{"protocol":"archibot.actor-outcome","version":1,"status":"unknown","actor":"test","source":{"kind":"command","id":42},"actor_execution_id":9,"attempt":1,"retry_at":null,"error_type":null}',
                'malformed',
            ],
        ];
    }

    #[DataProvider('protocolFailures')]
    public function test_rejects_missing_malformed_and_version_mismatched_protocol(
        string $output,
        string $message,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        PythonActorOutcome::fromProcessOutput($output);
    }

    public function test_rejects_wrong_actor(): void
    {
        $outcome = PythonActorOutcome::fromProcessOutput(json_encode([
            'protocol' => 'archibot.actor-outcome',
            'version' => 1,
            'status' => 'succeeded',
            'actor' => 'actual',
            'source' => ['kind' => 'command', 'id' => 42],
            'actor_execution_id' => 9,
            'attempt' => 1,
            'retry_at' => null,
            'error_type' => null,
        ]));

        $this->expectException(RuntimeException::class);
        $outcome->assertInvocation('other', 'command', 42);
    }

    public function test_rejects_wrong_durable_source(): void
    {
        $outcome = PythonActorOutcome::fromProcessOutput(json_encode([
            'protocol' => 'archibot.actor-outcome',
            'version' => 1,
            'status' => 'retrying',
            'actor' => 'test',
            'source' => ['kind' => 'command', 'id' => 42],
            'actor_execution_id' => 9,
            'attempt' => 1,
            'retry_at' => '2026-07-19T12:00:00+00:00',
            'error_type' => 'transient_network',
        ]));

        $this->expectException(RuntimeException::class);
        $outcome->assertSource('command', 43);
    }
}
