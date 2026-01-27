<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AIBackendController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\ExecutionController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\ToolController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public authentication routes
    Route::post('/auth/register', RegisterController::class);
    Route::post('/auth/login', LoginController::class);

    // Protected routes
    Route::middleware('auth:sanctum')->group(function (): void {
        // Authentication
        Route::post('/auth/logout', LogoutController::class);
        Route::get('/auth/user', [LoginController::class, 'user']);

        // Agents
        Route::apiResource('agents', AgentController::class);
        Route::get('agents/{agent}/executions', [AgentController::class, 'executions']);
        Route::post('agents/{agent}/tools', [AgentController::class, 'attachTools']);
        Route::delete('agents/{agent}/tools/{toolId}', [AgentController::class, 'detachTool']);

        // Tools
        Route::apiResource('tools', ToolController::class);

        // Files
        Route::apiResource('files', FileController::class)->except(['update']);
        Route::get('files/{file}/download', [FileController::class, 'download']);

        // Execution
        Route::post('agents/{agent}/execute', [ExecutionController::class, 'execute']);
        Route::post('agents/{agent}/stream', [ExecutionController::class, 'stream']);
        Route::get('executions', [ExecutionController::class, 'index']);
        Route::get('executions/{execution}', [ExecutionController::class, 'show']);
        Route::get('executions/{execution}/logs', [ExecutionController::class, 'logs']);
        Route::get('executions/{execution}/outputs', [ExecutionController::class, 'outputs']);
        Route::post('executions/{execution}/cancel', [ExecutionController::class, 'cancel']);

        // AI Backends
        Route::get('ai-backends', [AIBackendController::class, 'index']);
        Route::get('ai-backends/{backend}/models', [AIBackendController::class, 'models']);
    });
});
