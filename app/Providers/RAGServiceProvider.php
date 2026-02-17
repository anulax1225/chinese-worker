<?php

namespace App\Providers;

use App\Services\AIBackendManager;
use App\Services\RAG\EmbeddingService;
use App\Services\RAG\RAGContextBuilder;
use App\Services\RAG\RAGPipeline;
use App\Services\RAG\RetrievalService;
use Illuminate\Support\ServiceProvider;

class RAGServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register EmbeddingService as singleton
        $this->app->singleton(EmbeddingService::class, function ($app) {
            // Get the backend configured for embeddings
            $backendName = config('ai.rag.embedding_backend', 'openai');
            $backendManager = $app->make(AIBackendManager::class);
            $backend = $backendManager->driver($backendName);

            return new EmbeddingService($backend);
        });

        // Register RetrievalService as singleton
        $this->app->singleton(RetrievalService::class, function ($app) {
            return new RetrievalService(
                $app->make(EmbeddingService::class),
            );
        });

        // Register RAGContextBuilder as singleton
        $this->app->singleton(RAGContextBuilder::class);

        // Register RAGPipeline as singleton
        $this->app->singleton(RAGPipeline::class, function ($app) {
            return new RAGPipeline(
                $app->make(RetrievalService::class),
                $app->make(RAGContextBuilder::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
