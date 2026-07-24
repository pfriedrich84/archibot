<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Paperless\CanonicalPaperlessOrigin;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'queue' => $this->queueCheck(),
            'paperless_config' => $this->paperlessConfigCheck(),
            'python_runtime' => $this->pythonRuntimeCheck(),
        ];

        $status = 'ok';

        if (in_array('error', $checks, true)) {
            $status = 'error';
        } elseif (count(array_intersect($checks, ['stale', 'unknown', 'missing', 'warning'])) > 0) {
            $status = 'degraded';
        }

        return response()->json([
            'status' => $status,
            'checks' => $checks,
        ], $status === 'error' ? 503 : 200);
    }

    private function databaseCheck(): string
    {
        try {
            DB::select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'error';
        }
    }

    private function queueCheck(): string
    {
        try {
            $connection = config('queue.default');

            return filled($connection) ? 'ok' : 'unknown';
        } catch (Throwable) {
            return 'error';
        }
    }

    private function paperlessConfigCheck(): string
    {
        try {
            app(CanonicalPaperlessOrigin::class)->url();
            $token = User::query()->whereNotNull('paperless_token')->value('paperless_token');
            if (! is_string($token) || $token === '') {
                return 'missing';
            }

            return app(PaperlessClient::class)->ping($token) ? 'ok' : 'error';
        } catch (Throwable) {
            return 'missing';
        }
    }

    private function pythonRuntimeCheck(): string
    {
        $binaries = array_values(array_unique(array_filter([
            (string) config('archibot_workers.python_binary', 'python'),
            'python3',
        ])));

        foreach ($binaries as $binary) {
            try {
                $process = new Process([$binary, '--version']);
                $process->setTimeout(2);
                $process->run();

                if ($process->isSuccessful()) {
                    return 'ok';
                }
            } catch (Throwable) {
                // Try the next local Python binary before reporting an error.
            }
        }

        return 'error';
    }
}
