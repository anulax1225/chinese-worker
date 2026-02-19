<?php

namespace App\Services\WebFetch;

use App\DTOs\WebFetch\FetchedDocument;
use App\Jobs\EmbedFetchedPageJob;
use App\Models\FetchedPage;
use App\Models\FetchedPageChunk;

class FetchedPageStore
{
    /**
     * Persist a fetched document to the database.
     *
     * - If the URL was never fetched: create + chunk + dispatch embed job.
     * - If the URL was fetched and content is unchanged: return existing record.
     * - If the URL was fetched but content changed: update + re-chunk + dispatch embed job.
     */
    public function persist(FetchedDocument $document): FetchedPage
    {
        $urlHash = FetchedPage::hashUrl($document->url);
        $contentHash = FetchedPage::hashContent($document->text);

        $existing = FetchedPage::where('url_hash', $urlHash)->first();

        if ($existing !== null) {
            if ($existing->content_hash === $contentHash) {
                return $existing;
            }

            // Content changed — clear old chunks and update
            $existing->chunks()->delete();
            $existing->update([
                'title' => $document->title,
                'content_type' => $document->contentType,
                'content_hash' => $contentHash,
                'text' => $document->text,
                'fetched_at' => now(),
                'embedded_at' => null,
                'metadata' => $document->metadata,
            ]);

            $page = $existing;
        } else {
            $page = FetchedPage::create([
                'url' => $document->url,
                'url_hash' => $urlHash,
                'title' => $document->title,
                'content_type' => $document->contentType,
                'content_hash' => $contentHash,
                'text' => $document->text,
                'fetched_at' => now(),
                'metadata' => $document->metadata,
            ]);
        }

        $this->createChunks($page);

        if (config('ai.rag.enabled', false)) {
            EmbedFetchedPageJob::dispatch($page);
        }

        return $page;
    }

    /**
     * Split the page text into chunks and create FetchedPageChunk records.
     */
    protected function createChunks(FetchedPage $page): void
    {
        $maxTokens = config('document.chunking.default_max_tokens', 1000);
        $paragraphs = preg_split('/\n\n+/', $page->text);
        $chunks = [];
        $currentChunk = '';
        $currentTokens = 0;
        $offset = 0;
        $chunkIndex = 0;

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                $offset += strlen($paragraph) + 2;

                continue;
            }

            $tokens = (int) ceil(mb_strlen($paragraph) / 4);

            if ($currentTokens + $tokens > $maxTokens && ! empty($currentChunk)) {
                $startOffset = $offset - mb_strlen($currentChunk) - 2;
                $chunks[] = [
                    'fetched_page_id' => $page->id,
                    'chunk_index' => $chunkIndex++,
                    'content' => trim($currentChunk),
                    'token_count' => $currentTokens,
                    'start_offset' => max(0, $startOffset),
                    'end_offset' => $offset,
                    'content_hash' => hash('sha256', trim($currentChunk)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $currentChunk = $paragraph;
                $currentTokens = $tokens;
            } else {
                $currentChunk = empty($currentChunk) ? $paragraph : $currentChunk."\n\n".$paragraph;
                $currentTokens += $tokens;
            }

            $offset += strlen($paragraph) + 2;
        }

        if (! empty($currentChunk)) {
            $chunks[] = [
                'fetched_page_id' => $page->id,
                'chunk_index' => $chunkIndex,
                'content' => trim($currentChunk),
                'token_count' => $currentTokens,
                'start_offset' => max(0, $offset - mb_strlen($currentChunk)),
                'end_offset' => $offset,
                'content_hash' => hash('sha256', trim($currentChunk)),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (! empty($chunks)) {
            FetchedPageChunk::insert($chunks);
        }
    }
}
