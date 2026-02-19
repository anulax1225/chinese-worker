<?php

namespace App\Services\Embedding\Writers;

use App\Services\Embedding\EmbeddingService;
use Illuminate\Support\Collection;

abstract class AbstractEmbeddingWriter
{
    public function __construct(
        protected EmbeddingService $embeddingService,
    ) {}

    /**
     * Extract the embeddable text from a model instance.
     */
    abstract protected function extractText(mixed $item): string;

    /**
     * Persist the computed dense + sparse vectors back onto the model.
     *
     * @param  array<float>  $dense
     * @param  array<string, float>  $sparse
     */
    abstract protected function persistVector(
        mixed $item,
        array $dense,
        array $sparse,
        string $model,
        int $dimensions,
    ): void;

    /**
     * Embed a collection of models and persist the results.
     */
    public function write(Collection $items, ?string $model = null): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $model = $model ?? config('ai.rag.embedding_model', 'text-embedding-3-small');
        $texts = $items->map(fn ($item) => $this->extractText($item))->toArray();
        $vectors = $this->embeddingService->embedBatch($texts, $model);
        $dimensions = \count($vectors[0] ?? []);

        foreach ($items as $index => $item) {
            $sparse = $this->embeddingService->generateSparseEmbedding($texts[$index]);
            $this->persistVector($item, $vectors[$index], $sparse, $model, $dimensions);
        }
    }
}
