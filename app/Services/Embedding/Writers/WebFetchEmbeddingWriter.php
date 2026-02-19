<?php

namespace App\Services\Embedding\Writers;

use App\Models\FetchedPageChunk;
use Illuminate\Support\Facades\DB;

class WebFetchEmbeddingWriter extends AbstractEmbeddingWriter
{
    protected function extractText(mixed $item): string
    {
        /** @var FetchedPageChunk $item */
        return $item->content;
    }

    protected function persistVector(
        mixed $item,
        array $dense,
        array $sparse,
        string $model,
        int $dimensions,
    ): void {
        /** @var FetchedPageChunk $item */
        $item->update([
            'embedding_raw' => $dense,
            'embedding_model' => $model,
            'embedding_dimensions' => $dimensions,
            'embedding_generated_at' => now(),
            'sparse_vector' => $sparse,
        ]);

        if ($this->embeddingService->usesPgvector() && $dimensions === 1536) {
            DB::statement(
                'UPDATE fetched_page_chunks SET embedding = ?::vector WHERE id = ?',
                [$this->embeddingService->formatVectorForPgvector($dense), $item->id]
            );
        }
    }
}
