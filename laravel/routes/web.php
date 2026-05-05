<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\ReviewSuggestionController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\Workers\WorkerJobController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

Route::get('/healthz', fn () => response()->json([
    'status' => 'ok',
    'app' => 'archibot-laravel',
]))->name('healthz');

Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
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

    Route::get('review', [ReviewSuggestionController::class, 'index'])->name('review.index');
    Route::get('review/{reviewSuggestion}', [ReviewSuggestionController::class, 'show'])->name('review.show');
    Route::get('review/{reviewSuggestion}/preview', [ReviewSuggestionController::class, 'preview'])->name('review.preview');
    Route::post('review/{reviewSuggestion}/accept', [ReviewSuggestionController::class, 'accept'])->name('review.accept');
    Route::post('review/{reviewSuggestion}/reject', [ReviewSuggestionController::class, 'reject'])->name('review.reject');

    Route::get('worker-jobs', [WorkerJobController::class, 'index'])->name('worker-jobs.index');
    Route::post('worker-jobs', [WorkerJobController::class, 'store'])->name('worker-jobs.store');

    Route::get('admin/settings', [SettingsController::class, 'edit'])->name('admin.settings.edit');
    Route::patch('admin/settings', [SettingsController::class, 'update'])->name('admin.settings.update');
    Route::get('admin/audit-logs', [AuditLogController::class, 'index'])->name('admin.audit-logs.index');
});

require __DIR__.'/settings.php';
