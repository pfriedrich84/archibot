<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\ClassifyWithArchiBotController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmbeddingIndexController;
use App\Http\Controllers\EmbeddingsController;
use App\Http\Controllers\ErrorsController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MaintenanceCommandController;
use App\Http\Controllers\OcrReviewController;
use App\Http\Controllers\OperationsLogController;
use App\Http\Controllers\PaperlessAiSuggestController;
use App\Http\Controllers\PaperlessEventWebhookController;
use App\Http\Controllers\PaperlessMasterDataCaseController;
use App\Http\Controllers\EntityApprovalController;
use App\Http\Controllers\PipelineRunController;
use App\Http\Controllers\ReviewSuggestionController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\WebhookDeliveryController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::prefix(config('archibot.path_prefix'))->group(function () {
    Route::get('/healthz', HealthCheckController::class)->name('healthz');

    Route::post('/webhook', PaperlessEventWebhookController::class)->name('webhook.paperless');
    Route::post('/api/webhooks/paperless', PaperlessEventWebhookController::class)->name('api.webhooks.paperless');
    Route::post('/paperless-ai/v1/chat/completions', PaperlessAiSuggestController::class)->name('paperless-ai.suggest');

    Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
    Route::post('/setup/paperless-tags', [SetupController::class, 'paperlessTags'])
        ->middleware('throttle:setup-paperless')
        ->name('setup.paperless-tags');
    Route::post('/setup', [SetupController::class, 'store'])
        ->middleware('throttle:setup-paperless')
        ->name('setup.store');

    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])
            ->middleware('throttle:login')
            ->name('login.store');
    });

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->middleware('auth')
        ->name('logout');

    Route::inertia('/', 'Welcome')->name('home');

    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');

        Route::get('{segment}', [PaperlessMasterDataCaseController::class, 'index'])
            ->whereIn('segment', ['tags', 'correspondents', 'doctypes'])
            ->name('entities.index');
        Route::post('{segment}/entity-approvals/{entityApproval}/approve', [EntityApprovalController::class, 'approve'])
            ->whereIn('segment', ['tags', 'correspondents', 'doctypes'])
            ->name('entities.approve');
        Route::post('{segment}/entity-approvals/{entityApproval}/reject', [EntityApprovalController::class, 'reject'])
            ->whereIn('segment', ['tags', 'correspondents', 'doctypes'])
            ->name('entities.reject');
        Route::post('{segment}/entity-approvals/{entityApproval}/unblacklist', [EntityApprovalController::class, 'unblacklist'])
            ->whereIn('segment', ['tags', 'correspondents', 'doctypes'])
            ->name('entities.unblacklist');
        Route::post('{segment}/entity-approvals/{paperlessMasterDataCase}/approve', [PaperlessMasterDataCaseController::class, 'approve'])
            ->whereIn('segment', ['tags', 'correspondents', 'doctypes']);
        Route::post('{segment}/entity-approvals/{paperlessMasterDataCase}/reject', [PaperlessMasterDataCaseController::class, 'reject'])
            ->whereIn('segment', ['tags', 'correspondents', 'doctypes']);
        Route::post('{segment}/entity-approvals/{paperlessMasterDataCase}/unblacklist', [PaperlessMasterDataCaseController::class, 'unblacklist'])
            ->whereIn('segment', ['tags', 'correspondents', 'doctypes']);

        Route::get('review', [ReviewSuggestionController::class, 'index'])->name('review.index');
        Route::get('review/classify-with-archibot', [ClassifyWithArchiBotController::class, 'create'])->name('classify-with-archibot.create');
        Route::post('review/classify-with-archibot', [ClassifyWithArchiBotController::class, 'store'])->name('classify-with-archibot.store');
        Route::post('review/bulk/accept', [ReviewSuggestionController::class, 'bulkAccept'])->name('review.bulk.accept');
        Route::post('review/bulk/reject', [ReviewSuggestionController::class, 'bulkReject'])->name('review.bulk.reject');
        Route::get('review/{reviewSuggestion}', [ReviewSuggestionController::class, 'show'])->name('review.show');
        Route::get('review/{reviewSuggestion}/preview', [ReviewSuggestionController::class, 'preview'])->name('review.preview');
        Route::post('review/{reviewSuggestion}/save', [ReviewSuggestionController::class, 'save'])->name('review.save');
        Route::post('review/{reviewSuggestion}/accept', [ReviewSuggestionController::class, 'accept'])->name('review.accept');
        Route::post('review/{reviewSuggestion}/reject', [ReviewSuggestionController::class, 'reject'])->name('review.reject');
        Route::post('review/{reviewSuggestion}/reprocess', [ReviewSuggestionController::class, 'reprocess'])->name('review.reprocess');

        Route::get('ocr-reviews', [OcrReviewController::class, 'index'])->name('ocr-reviews.index');
        Route::post('ocr-reviews', [OcrReviewController::class, 'store'])->name('ocr-reviews.store');
        Route::get('ocr-reviews/{ocrReview}', [OcrReviewController::class, 'show'])->name('ocr-reviews.show');
        Route::post('ocr-reviews/{ocrReview}/approve', [OcrReviewController::class, 'approve'])->name('ocr-reviews.approve');
        Route::post('ocr-reviews/{ocrReview}/reject', [OcrReviewController::class, 'reject'])->name('ocr-reviews.reject');

        Route::middleware('admin')->group(function () {
            Route::get('stats', StatsController::class)->name('stats.index');
            Route::get('errors', ErrorsController::class)->name('errors.index');
            Route::get('operations-log', OperationsLogController::class)->name('operations-log.index');
            Route::get('pipeline-runs', [PipelineRunController::class, 'index'])->name('pipeline-runs.index');
            Route::get('pipeline-runs/{pipelineRun}', [PipelineRunController::class, 'show'])->name('pipeline-runs.show');
            Route::post('pipeline-runs/{pipelineRun}/retry', [PipelineRunController::class, 'retry'])->name('pipeline-runs.retry');
            Route::post('pipeline-runs/{pipelineRun}/retry-failed-items', [PipelineRunController::class, 'retryFailedItems'])->name('pipeline-runs.retry-failed-items');
            Route::post('pipeline-runs/{pipelineRun}/cancel', [PipelineRunController::class, 'cancel'])->name('pipeline-runs.cancel');
            Route::post('embedding-index/build', [EmbeddingIndexController::class, 'build'])->name('embedding-index.build');
            Route::post('embedding-index/mark-stale', [EmbeddingIndexController::class, 'markStale'])->name('embedding-index.mark-stale');
            Route::post('maintenance/poll', [MaintenanceCommandController::class, 'poll'])->name('maintenance.poll');
            Route::post('maintenance/reindex', [MaintenanceCommandController::class, 'reindex'])->name('maintenance.reindex');
            Route::get('webhook-deliveries', [WebhookDeliveryController::class, 'index'])->name('webhook-deliveries.index');
            Route::get('webhook-deliveries/{webhookDelivery}', [WebhookDeliveryController::class, 'show'])->name('webhook-deliveries.show');
            Route::post('webhook-deliveries/{webhookDelivery}/retry', [WebhookDeliveryController::class, 'retry'])->name('webhook-deliveries.retry');
            Route::post('webhook-deliveries/{webhookDelivery}/dismiss', [WebhookDeliveryController::class, 'dismiss'])->name('webhook-deliveries.dismiss');
            Route::get('embeddings', [EmbeddingsController::class, 'index'])->name('embeddings.index');
            Route::get('admin/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit-logs.index');
            Route::get('admin/maintenance', [MaintenanceController::class, 'index'])->name('admin.maintenance.index');
            Route::post('admin/maintenance/recover-pipeline-actors', [MaintenanceController::class, 'recoverPipelineActors'])->name('admin.maintenance.recover-pipeline-actors');
            Route::post('admin/maintenance/document-pipeline', [MaintenanceController::class, 'startDocumentPipeline'])->name('admin.maintenance.document-pipeline');
            Route::post('admin/maintenance/commands', [MaintenanceController::class, 'startCommand'])->name('admin.maintenance.commands');
            Route::post('admin/maintenance/reset', [MaintenanceController::class, 'reset'])->name('admin.maintenance.reset');
        });

        Route::get('admin/settings/{section?}', [SettingsController::class, 'edit'])->name('admin.settings.edit');
        Route::post('admin/settings/ai-models', [SettingsController::class, 'aiModels'])
            ->middleware('throttle:model-discovery')
            ->name('admin.settings.ai-models');
        Route::post('admin/settings/paperless-ai-state', [SettingsController::class, 'refreshPaperlessAiState'])
            ->name('admin.settings.paperless-ai-state');
        Route::post('admin/settings/ai-models/validate', [SettingsController::class, 'validateAiModel'])
            ->middleware('throttle:model-discovery')
            ->name('admin.settings.ai-models.validate');
        Route::patch('admin/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
        Route::patch('admin/settings/prompts/{prompt}', [SettingsController::class, 'updatePrompt'])->name('admin.settings.prompts.update');
        Route::delete('admin/settings/prompts/{prompt}', [SettingsController::class, 'resetPrompt'])->name('admin.settings.prompts.reset');
    });

    require __DIR__.'/settings.php';
});
