<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::get('/healthz', fn () => response()->json([
    'status' => 'ok',
    'app' => 'archibot-laravel',
]))->name('healthz');

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
