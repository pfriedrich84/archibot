<?php

namespace App\Http\Controllers;

use App\Models\WorkerJob;
use App\Services\LegacyPythonState;
use Inertia\Inertia;
use Inertia\Response;

class ErrorsController extends Controller
{
    public function __invoke(LegacyPythonState $legacyPythonState): Response
    {
        return Inertia::render('diagnostics/Errors', [
            'failedJobs' => WorkerJob::query()
                ->whereIn('status', [WorkerJob::STATUS_FAILED, WorkerJob::STATUS_PARTIALLY_FAILED, WorkerJob::STATUS_CANCELLED])
                ->latest()
                ->limit(25)
                ->get()
                ->map(fn (WorkerJob $job) => [
                    'id' => $job->id,
                    'type' => $job->type,
                    'status' => $job->status,
                    'error' => $job->error,
                    'progress' => $job->progress ?? [],
                    'created_at' => $job->created_at?->toISOString(),
                    'finished_at' => $job->finished_at?->toISOString(),
                ])
                ->values(),
            'legacyErrors' => $legacyPythonState->recentErrors(25),
        ]);
    }
}
