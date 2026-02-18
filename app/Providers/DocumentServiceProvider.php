<?php

namespace App\Providers;

use App\Services\Document\CleaningPipeline;
use App\Services\Document\CleaningSteps\FixBrokenLinesStep;
use App\Services\Document\CleaningSteps\NormalizeEncodingStep;
use App\Services\Document\CleaningSteps\NormalizeQuotesStep;
use App\Services\Document\CleaningSteps\NormalizeWhitespaceStep;
use App\Services\Document\CleaningSteps\RemoveBoilerplateStep;
use App\Services\Document\CleaningSteps\RemoveControlCharactersStep;
use App\Services\Document\CleaningSteps\RemoveHeadersFootersStep;
use App\Services\Document\DocumentIngestionService;
use App\Services\Document\Extractors\PlainTextExtractor;
use App\Services\Document\Extractors\TextractExtractor;
use App\Services\Document\StructurePipeline;
use App\Services\Document\StructureProcessors\HeadingDetector;
use App\Services\Document\StructureProcessors\ListNormalizer;
use App\Services\Document\StructureProcessors\ParagraphNormalizer;
use App\Services\Document\TextExtractorRegistry;
use App\Services\FileService;
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

            // Register Textract first for multi-format support (PDF, DOCX, images, etc.)
            $registry->register(new TextractExtractor);

            // PlainText as fallback for text/* types
            $registry->register(new PlainTextExtractor);

            return $registry;
        });

        $this->app->singleton(CleaningPipeline::class, function ($app) {
            $pipeline = new CleaningPipeline;

            // Register cleaning steps in priority order
            $pipeline->register(new NormalizeEncodingStep);
            $pipeline->register(new RemoveControlCharactersStep);
            $pipeline->register(new NormalizeWhitespaceStep);
            $pipeline->register(new FixBrokenLinesStep);
            $pipeline->register(new RemoveHeadersFootersStep);
            $pipeline->register(new RemoveBoilerplateStep);
            $pipeline->register(new NormalizeQuotesStep);

            return $pipeline;
        });

        $this->app->singleton(StructurePipeline::class, function ($app) {
            $pipeline = new StructurePipeline;

            // Register structure processors in priority order
            $pipeline->register(new HeadingDetector);
            $pipeline->register(new ListNormalizer);
            $pipeline->register(new ParagraphNormalizer);

            return $pipeline;
        });

        $this->app->singleton(DocumentIngestionService::class, function ($app) {
            return new DocumentIngestionService(
                $app->make(TextExtractorRegistry::class),
                $app->make(FileService::class)
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
