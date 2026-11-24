<?php

use App\Http\Controllers\Admin\OperatorReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('studio') : Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The starter kit sends users to "dashboard" after login; the studio is our dashboard.
    Route::redirect('dashboard', 'studio')->name('dashboard');

    Route::get('studio', [GenerationController::class, 'studio'])->name('studio');
    Route::get('history', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('generations.store');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:240,1')
        ->name('generations.estimate');
    Route::post('generations/{generation}/sync', [GenerationController::class, 'sync'])
        ->middleware('throttle:20,1')
        ->name('generations.sync');

    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');
    Route::post('credits', [CreditController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('credits.store');

    Route::get('admin/report', OperatorReportController::class)->name('admin.report');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
