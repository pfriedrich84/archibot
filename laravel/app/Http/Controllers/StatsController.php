<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\EntityApproval;
use App\Models\ReviewSuggestion;
use App\Models\WorkerJob;
use App\Services\LegacyPythonState;
use Inertia\Inertia;
use Inertia\Response;

class StatsController extends Controller
{
    public function __invoke(LegacyPythonState $legacyPythonState): Response
    {
        return Inertia::render('stats/Index', [
            'review' => [
                'pending' => ReviewSuggestion::query()->where('status', ReviewSuggestion::STATUS_PENDING)->count(),
                'accepted' => ReviewSuggestion::query()->where('status', ReviewSuggestion::STATUS_ACCEPTED)->count(),
                'rejected' => ReviewSuggestion::query()->where('status', ReviewSuggestion::STATUS_REJECTED)->count(),
            ],
            'entities' => [
                'pending' => EntityApproval::query()->where('status', EntityApproval::STATUS_PENDING)->count(),
                'approved' => EntityApproval::query()->where('status', EntityApproval::STATUS_APPROVED)->count(),
                'rejected' => EntityApproval::query()->where('status', EntityApproval::STATUS_REJECTED)->count(),
            ],
            'workers' => [
                'queued' => WorkerJob::query()->where('status', WorkerJob::STATUS_QUEUED)->count(),
                'running' => WorkerJob::query()->whereIn('status', [WorkerJob::STATUS_RUNNING, WorkerJob::STATUS_CANCELLING])->count(),
                'failed' => WorkerJob::query()->whereIn('status', [WorkerJob::STATUS_FAILED, WorkerJob::STATUS_PARTIALLY_FAILED])->count(),
                'succeeded' => WorkerJob::query()->where('status', WorkerJob::STATUS_SUCCEEDED)->count(),
            ],
            'chat' => [
                'sessions' => ChatSession::query()->count(),
            ],
            'python' => $legacyPythonState->stats(),
        ]);
    }
}
