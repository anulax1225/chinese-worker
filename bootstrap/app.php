<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\CleanupTempFilesJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Clean up temporary files daily at 2 AM
        $schedule->job(new CleanupTempFilesJob)->dailyAt('02:00');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
