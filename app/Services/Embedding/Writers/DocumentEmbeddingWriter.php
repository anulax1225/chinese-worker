<?php

namespace App\Services\Embedding\Writers;

use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;

class DocumentEmbeddingWriter extends AbstractEmbeddingWriter
{
    protected function extractText(mixed $item): string
    {
        /** @var DocumentChunk $item */
        return $item->content;
    }

    protected function persistVector(
        mixed $item,
        array $dense,
        array $sparse,
        string $model,
        int $dimensions,
    ): void {
        /** @var DocumentChunk $item */
        $item->update([
            'embedding_raw' => $dense,
            'embedding_model' => $model,
            'embedding_dimensions' => $dimensions,
            'embedding_generated_at' => now(),
            'sparse_vector' => $sparse,
        ]);

        if ($this->embeddingService->usesPgvector() && $dimensions === 1536) {
            DB::statement(
                'UPDATE document_chunks SET embedding = ?::vector WHERE id = ?',
                [$this->embeddingService->formatVectorForPgvector($dense), $item->id]
            );
        }
    }

    /**
     * Embed all un-embedded chunks for a given document.
     */
    public function writeForDocument(int $documentId, ?string $model = null): void
    {
        $chunks = DocumentChunk::where('document_id', $documentId)
            ->whereNull('embedding_generated_at')
            ->get();

        $this->write($chunks, $model);
    }

    /**
     * Re-embed chunks regardless of existing embeddings.
     */
    public function rewrite(mixed $chunks, ?string $model = null): void
    {
        $this->write(collect($chunks), $model);
    }
}
