<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Public authentication routes
    Route::post('/auth/register', RegisterController::class);
    Route::post('/auth/login', LoginController::class);

    // Protected routes
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', LogoutController::class);
        Route::get('/auth/user', function (Request $request) {
            return $request->user();
        });
    });
});
