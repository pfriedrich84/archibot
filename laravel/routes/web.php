<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmbeddingsController;
use App\Http\Controllers\EntityApprovalController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\OcrReviewController;
use App\Http\Controllers\PaperlessWebhookController;
use App\Http\Controllers\ReviewSuggestionController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\Workers\WorkerJobController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::prefix(config('archibot.path_prefix'))->group(function () {
    Route::get('/healthz', fn () => response()->json([
        'status' => 'ok',
        'app' => 'archibot',
    ]))->name('healthz');

    Route::post('/webhook/new', [PaperlessWebhookController::class, 'new'])->name('webhook.new');
    Route::post('/webhook/edit', [PaperlessWebhookController::class, 'edit'])->name('webhook.edit');

    Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
    Route::post('/setup/paperless-tags', [SetupController::class, 'paperlessTags'])->name('setup.paperless-tags');
    Route::post('/setup/ollama-models', [SetupController::class, 'ollamaModels'])->name('setup.ollama-models');
    Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

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

        Route::get('{segment}', [EntityApprovalController::class, 'index'])
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

        Route::get('review', [ReviewSuggestionController::class, 'index'])->name('review.index');
        Route::get('review/{reviewSuggestion}', [ReviewSuggestionController::class, 'show'])->name('review.show');
        Route::get('review/{reviewSuggestion}/preview', [ReviewSuggestionController::class, 'preview'])->name('review.preview');
        Route::post('review/{reviewSuggestion}/accept', [ReviewSuggestionController::class, 'accept'])->name('review.accept');
        Route::post('review/{reviewSuggestion}/reject', [ReviewSuggestionController::class, 'reject'])->name('review.reject');

        Route::get('ocr-reviews', [OcrReviewController::class, 'index'])->name('ocr-reviews.index');
        Route::post('ocr-reviews', [OcrReviewController::class, 'store'])->name('ocr-reviews.store');
        Route::get('ocr-reviews/{ocrReview}', [OcrReviewController::class, 'show'])->name('ocr-reviews.show');
        Route::post('ocr-reviews/{ocrReview}/approve', [OcrReviewController::class, 'approve'])->name('ocr-reviews.approve');
        Route::post('ocr-reviews/{ocrReview}/reject', [OcrReviewController::class, 'reject'])->name('ocr-reviews.reject');
        Route::post('ocr-reviews/{ocrReview}/restore', [OcrReviewController::class, 'restore'])->name('ocr-reviews.restore');

        Route::get('worker-jobs', [WorkerJobController::class, 'index'])->name('worker-jobs.index');
        Route::post('worker-jobs', [WorkerJobController::class, 'store'])->name('worker-jobs.store');
        Route::post('worker-jobs/{workerJob}/stop', [WorkerJobController::class, 'stop'])->name('worker-jobs.stop');
        Route::post('worker-jobs/{workerJob}/retry', [WorkerJobController::class, 'retry'])->name('worker-jobs.retry');
        Route::get('embeddings', [EmbeddingsController::class, 'index'])->name('embeddings.index');

        Route::get('admin/settings', [SettingsController::class, 'edit'])->name('admin.settings.edit');
        Route::patch('admin/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
        Route::get('admin/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit-logs.index');
    });

    require __DIR__.'/settings.php';
});
