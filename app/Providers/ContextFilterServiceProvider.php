<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\ContextFilterStrategy;
use App\Contracts\TokenEstimator;
use App\Services\AIBackendManager;
use App\Services\ContextFilter\ContextFilterManager;
use App\Services\ContextFilter\Strategies\NoOpStrategy;
use App\Services\ContextFilter\Strategies\SlidingWindowStrategy;
use App\Services\ContextFilter\Strategies\SummaryBoundaryStrategy;
use App\Services\ContextFilter\Strategies\TokenBudgetStrategy;
use App\Services\ContextFilter\SummarizationService;
use App\Services\ContextFilter\TokenEstimators\CharRatioEstimator;
use Illuminate\Support\ServiceProvider;

class ContextFilterServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register default token estimator
        $this->app->singleton(TokenEstimator::class, CharRatioEstimator::class);

        // Register individual strategies
        $this->app->singleton(NoOpStrategy::class);
        $this->app->singleton(SlidingWindowStrategy::class);
        $this->app->singleton(TokenBudgetStrategy::class, function ($app) {
            return new TokenBudgetStrategy($app->make(TokenEstimator::class));
        });

        // Register summarization service
        $this->app->singleton(SummarizationService::class, function ($app) {
            return new SummarizationService(
                backendManager: $app->make(AIBackendManager::class),
                tokenEstimator: $app->make(TokenEstimator::class),
            );
        });

        // Register summary boundary strategy
        $this->app->singleton(SummaryBoundaryStrategy::class);

        // Tag all strategies for container resolution
        $this->app->tag([
            NoOpStrategy::class,
            SlidingWindowStrategy::class,
            TokenBudgetStrategy::class,
            SummaryBoundaryStrategy::class,
        ], ContextFilterStrategy::class);

        // Register the manager
        $this->app->singleton(ContextFilterManager::class, function ($app) {
            return new ContextFilterManager(
                strategies: $app->tagged(ContextFilterStrategy::class),
                fallbackStrategy: $app->make(NoOpStrategy::class),
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
