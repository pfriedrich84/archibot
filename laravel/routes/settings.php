<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('settings', '/settings/appearance');
    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
