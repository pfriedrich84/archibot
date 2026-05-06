<?php

use App\Http\Controllers\Settings\McpTokenController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('settings', config('archibot.path_prefix') ? '/'.config('archibot.path_prefix').'/settings/appearance' : '/settings/appearance');
    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
    Route::get('settings/mcp-tokens', [McpTokenController::class, 'index'])->name('mcp-tokens.index');
    Route::post('settings/mcp-tokens', [McpTokenController::class, 'store'])->name('mcp-tokens.store');
    Route::delete('settings/mcp-tokens/{mcpToken}', [McpTokenController::class, 'destroy'])->name('mcp-tokens.destroy');
});
