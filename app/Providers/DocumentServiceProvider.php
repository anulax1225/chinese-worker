<?php

namespace App\Providers;

use App\Services\Document\DocumentIngestionService;
use App\Services\Document\Extractors\PlainTextExtractor;
use App\Services\Document\TextExtractorRegistry;
use Illuminate\Support\ServiceProvider;

class DocumentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(TextExtractorRegistry::class, function ($app) {
            $registry = new TextExtractorRegistry;

            // Register built-in extractors
            $registry->register(new PlainTextExtractor);
            // Future: PDF, DOCX, HTML extractors

            return $registry;
        });

        $this->app->singleton(DocumentIngestionService::class, function ($app) {
            return new DocumentIngestionService(
                $app->make(TextExtractorRegistry::class)
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
