<?php

use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\LogoutController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\DashboardController;
use Illuminate\Support\Facades\Route;

// Redirect root to dashboard if authenticated, otherwise to login
Route::get('/', function () {
    return auth()->check() ? redirect('/dashboard') : redirect('/login');
})->name('home');

// Guest routes (unauthenticated users only)
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);

    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'register']);
});

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', LogoutController::class)->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Agents
    // Route::resource('agents', AgentController::class);

    // Tools
    // Route::resource('tools', ToolController::class);

    // Files
    // Route::resource('files', FileController::class);

    // Executions
    // Route::resource('executions', ExecutionController::class)->only(['index', 'show']);

    // AI Backends
    // Route::get('/ai-backends', [AIBackendController::class, 'index'])->name('ai-backends.index');
});
