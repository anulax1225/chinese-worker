<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AIBackendController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\ConversationSummaryController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\SystemPromptController;
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
        Route::post('agents/{agent}/tools', [AgentController::class, 'attachTools']);
        Route::delete('agents/{agent}/tools/{toolId}', [AgentController::class, 'detachTool']);

        // Tools
        Route::apiResource('tools', ToolController::class);

        // System Prompts
        Route::apiResource('system-prompts', SystemPromptController::class);

        // Files
        Route::apiResource('files', FileController::class)->except(['update']);
        Route::get('files/{file}/download', [FileController::class, 'download']);

        // Conversations
        Route::post('agents/{agent}/conversations', [ConversationController::class, 'store']);
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::get('conversations/{conversation}', [ConversationController::class, 'show']);
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'sendMessage']);
        Route::get('conversations/{conversation}/status', [ConversationController::class, 'status']);
        Route::post('conversations/{conversation}/stop', [ConversationController::class, 'stop']);
        Route::get('conversations/{conversation}/stream', [ConversationController::class, 'stream']);
        Route::post('conversations/{conversation}/tool-results', [ConversationController::class, 'submitToolResult']);
        Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy']);

        // Conversation Summaries
        Route::get('conversations/{conversation}/summaries', [ConversationSummaryController::class, 'index']);
        Route::post('conversations/{conversation}/summaries', [ConversationSummaryController::class, 'store']);
        Route::get('conversations/{conversation}/summaries/{summary}', [ConversationSummaryController::class, 'show']);

        // AI Backends
        Route::get('ai-backends', [AIBackendController::class, 'index']);
        Route::get('ai-backends/{backend}/models', [AIBackendController::class, 'models']);
        Route::post('ai-backends/{backend}/models/pull', [AIBackendController::class, 'pullModel']);
        Route::get('ai-backends/{backend}/models/pull/{pullId}/stream', [AIBackendController::class, 'streamPullProgress']);
        Route::get('ai-backends/{backend}/models/{model}', [AIBackendController::class, 'showModel'])
            ->where('model', '[a-zA-Z0-9_\-\.:\/]+');
        Route::delete('ai-backends/{backend}/models/{model}', [AIBackendController::class, 'deleteModel'])
            ->where('model', '[a-zA-Z0-9_\-\.:\/]+');

        // Documents
        Route::get('documents/supported-types', [DocumentController::class, 'supportedTypes']);
        Route::apiResource('documents', DocumentController::class)->except(['update']);
        Route::get('documents/{document}/stages', [DocumentController::class, 'stages']);
        Route::get('documents/{document}/chunks', [DocumentController::class, 'chunks']);
        Route::get('documents/{document}/preview', [DocumentController::class, 'preview']);
        Route::post('documents/{document}/reprocess', [DocumentController::class, 'reprocess']);
    });
});
