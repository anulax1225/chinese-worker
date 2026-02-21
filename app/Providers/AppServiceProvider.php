<?php

namespace App\Providers;

use App\Services\Search\SearchService;
use App\Services\WebFetch\WebFetchService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(FortifyServiceProvider::class);
        $this->app->register(AIServiceProvider::class);
        $this->app->register(HorizonServiceProvider::class);
        $this->app->register(DocumentServiceProvider::class);
        $this->app->register(RAGServiceProvider::class);
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        // Register SearchService as singleton
        $this->app->singleton(SearchService::class, SearchService::make(...));

        // Register WebFetchService as singleton
        $this->app->singleton(WebFetchService::class, WebFetchService::make(...));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();
        $this->configureDefaults();
        $this->configureGates();
        $this->configureRateLimiting();
    }

    protected function configureGates(): void
    {
        // Gate for managing AI models (pull, delete, etc.)
        // Can be restricted to admin users when role system is added
        Gate::define('manage-ai-models', fn ($_user) => true);
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if (! config('app.api_rate_limit_enabled', true)) {
                return Limit::none();
            }

            return Limit::perMinute(
                config('app.api_rate_limit', 60),
            )->by($request->user()?->id ?: $request->ip());
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Prevent N+1 queries in non-production environments
        Model::preventLazyLoading(! app()->isProduction());

        Password::defaults(
            fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }
}
