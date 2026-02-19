<?php

namespace App\Providers;

use App\Services\AIBackendManager;
use App\Services\Embedding\EmbeddingService;
use App\Services\Embedding\VectorSearchService;
use App\Services\Embedding\Writers\DocumentEmbeddingWriter;
use App\Services\Embedding\Writers\MessageEmbeddingWriter;
use App\Services\Embedding\Writers\WebFetchEmbeddingWriter;
use App\Services\RAG\RAGContextBuilder;
use App\Services\RAG\RAGPipeline;
use App\Services\WebFetch\FetchedPageStore;
use Illuminate\Support\ServiceProvider;

class RAGServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Core embedding service — pure text → vector + cache
        $this->app->singleton(EmbeddingService::class, function ($app) {
            $backendName = config('ai.rag.embedding_backend', 'openai');
            $backendManager = $app->make(AIBackendManager::class);
            $backend = $backendManager->driver($backendName);

            return new EmbeddingService($backend);
        });

        // Generic vector search — no model knowledge, accepts any Builder
        $this->app->singleton(VectorSearchService::class, function ($app) {
            return new VectorSearchService(
                $app->make(EmbeddingService::class),
            );
        });

        // Domain-specific writers
        $this->app->singleton(DocumentEmbeddingWriter::class, function ($app) {
            return new DocumentEmbeddingWriter($app->make(EmbeddingService::class));
        });

        $this->app->singleton(MessageEmbeddingWriter::class, function ($app) {
            return new MessageEmbeddingWriter($app->make(EmbeddingService::class));
        });

        $this->app->singleton(WebFetchEmbeddingWriter::class, function ($app) {
            return new WebFetchEmbeddingWriter($app->make(EmbeddingService::class));
        });

        // WebFetch DB persistence layer
        $this->app->singleton(FetchedPageStore::class);

        // RAG pipeline
        $this->app->singleton(RAGContextBuilder::class);

        $this->app->singleton(RAGPipeline::class, function ($app) {
            return new RAGPipeline(
                $app->make(VectorSearchService::class),
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
