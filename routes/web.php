<?php

use App\Http\Controllers\Web\AgentController;
use App\Http\Controllers\Web\ConversationController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\FileController;
use App\Http\Controllers\Web\SettingsController;
use App\Http\Controllers\Web\ToolController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public routes
Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : Inertia::render('Welcome');
})->name('home');

// Authenticated routes
Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Resource routes
    Route::resource('agents', AgentController::class);
    Route::resource('tools', ToolController::class);
    Route::resource('files', FileController::class)->except(['update']);
    Route::resource('conversations', ConversationController::class);

    // Settings - Consolidated page
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');

    // Settings - Form submissions (keep individual routes for form actions)
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::put('/profile', [SettingsController::class, 'updateProfile'])->name('profile.update');
        Route::put('/password', [SettingsController::class, 'updatePassword'])->name('password.update');
        Route::post('/tokens', [SettingsController::class, 'createToken'])->name('tokens.store');
        Route::delete('/tokens/{token}', [SettingsController::class, 'deleteToken'])->name('tokens.destroy');
    });
});
