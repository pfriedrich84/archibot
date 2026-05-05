<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\ReviewSuggestion;
use App\Models\SetupState;
use App\Models\WorkerJob;
use App\Services\Paperless\PaperlessClient;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $paperlessUrl = AppSetting::getValue('paperless.url');
        $inboxTagId = (int) (AppSetting::getValue('paperless.inbox_tag_id', '0') ?? 0);
        $paperlessAvailable = null;
        $paperlessError = null;

        if ($paperlessUrl && $request->user()->paperless_token) {
            try {
                $paperlessAvailable = app(PaperlessClient::class, ['baseUrl' => $paperlessUrl])
                    ->ping($request->user()->paperless_token);
            } catch (\Throwable $exception) {
                $paperlessAvailable = false;
                $paperlessError = $exception->getMessage();
            }
        }

        return Inertia::render('Dashboard', [
            'status' => [
                'setup_complete' => SetupState::current()->complete,
                'paperless_url_configured' => filled($paperlessUrl),
                'paperless_available' => $paperlessAvailable,
                'paperless_error' => $paperlessError,
                'inbox_tag_id' => $inboxTagId,
            ],
            'counts' => [
                'pending_reviews' => ReviewSuggestion::query()
                    ->where('status', ReviewSuggestion::STATUS_PENDING)
                    ->count(),
                'queued_or_running_workers' => WorkerJob::query()
                    ->whereIn('status', [WorkerJob::STATUS_QUEUED, WorkerJob::STATUS_RUNNING])
                    ->count(),
                'failed_workers' => WorkerJob::query()
                    ->where('status', WorkerJob::STATUS_FAILED)
                    ->count(),
            ],
            'recentWorkerJobs' => WorkerJob::query()
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (WorkerJob $job) => [
                    'id' => $job->id,
                    'type' => $job->type,
                    'status' => $job->status,
                    'created_at' => $job->created_at?->toISOString(),
                    'finished_at' => $job->finished_at?->toISOString(),
                ]),
        ]);
    }
}
