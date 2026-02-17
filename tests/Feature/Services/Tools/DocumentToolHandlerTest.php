<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\RAG\RAGPipeline;
use App\Services\Tools\DocumentToolHandler;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->create(['user_id' => $this->user->id]);
    $this->conversation = Conversation::factory()->create([
        'user_id' => $this->user->id,
        'agent_id' => $this->agent->id,
    ]);
    $this->handler = new DocumentToolHandler(
        $this->conversation,
        app(RAGPipeline::class),
    );
});

describe('document_list', function () {
    test('returns empty message when no documents attached', function () {
        $result = $this->handler->execute('document_list', []);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('No documents attached');
    });

    test('lists attached documents with their info', function () {
        $document = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
            'title' => 'Test Document',
        ]);

        // Create some chunks
        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'First chunk content',
            'token_count' => 50,
            'start_offset' => 0,
            'end_offset' => 100,
            'created_at' => now(),
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Second chunk content',
            'token_count' => 60,
            'start_offset' => 100,
            'end_offset' => 200,
            'created_at' => now(),
        ]);

        $this->conversation->documents()->attach($document->id, [
            'preview_chunks' => 2,
            'preview_tokens' => 110,
            'attached_at' => now(),
        ]);

        $result = $this->handler->execute('document_list', []);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('Test Document');
        expect($result->output)->toContain((string) $document->id);
        expect($result->output)->toContain('Chunks: 2');
    });

    test('lists multiple attached documents', function () {
        $doc1 = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
            'title' => 'First Document',
        ]);
        $doc2 = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
            'title' => 'Second Document',
        ]);

        $this->conversation->documents()->attach($doc1->id, [
            'preview_chunks' => 2,
            'preview_tokens' => 100,
            'attached_at' => now(),
        ]);
        $this->conversation->documents()->attach($doc2->id, [
            'preview_chunks' => 2,
            'preview_tokens' => 100,
            'attached_at' => now(),
        ]);

        $result = $this->handler->execute('document_list', []);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('First Document');
        expect($result->output)->toContain('Second Document');
    });
});

describe('document_info', function () {
    test('returns document info with sections', function () {
        $document = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
            'title' => 'Detailed Document',
        ]);

        // Create chunks with section titles
        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Introduction content',
            'token_count' => 50,
            'start_offset' => 0,
            'end_offset' => 100,
            'section_title' => 'Introduction',
            'created_at' => now(),
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Methods content',
            'token_count' => 60,
            'start_offset' => 100,
            'end_offset' => 200,
            'section_title' => 'Methods',
            'created_at' => now(),
        ]);

        $this->conversation->documents()->attach($document->id, [
            'preview_chunks' => 2,
            'preview_tokens' => 110,
            'attached_at' => now(),
        ]);

        $result = $this->handler->execute('document_info', ['document_id' => $document->id]);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('Detailed Document');
        expect($result->output)->toContain('Introduction');
        expect($result->output)->toContain('Methods');
    });

    test('returns error for non-attached document', function () {
        $document = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
        ]);

        $result = $this->handler->execute('document_info', ['document_id' => $document->id]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('not found');
    });

    test('returns error for non-existent document', function () {
        $result = $this->handler->execute('document_info', ['document_id' => 99999]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('not found');
    });

    test('returns error when document_id is missing', function () {
        $result = $this->handler->execute('document_info', []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('document_id');
    });
});

describe('document_get_chunks', function () {
    beforeEach(function () {
        $this->document = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
            'title' => 'Chunked Document',
        ]);

        // Create 5 chunks
        for ($i = 0; $i < 5; $i++) {
            DocumentChunk::create([
                'document_id' => $this->document->id,
                'chunk_index' => $i,
                'content' => "Content of chunk {$i}",
                'token_count' => 50,
                'start_offset' => $i * 100,
                'end_offset' => ($i + 1) * 100,
                'created_at' => now(),
            ]);
        }

        $this->conversation->documents()->attach($this->document->id, [
            'preview_chunks' => 2,
            'preview_tokens' => 100,
            'attached_at' => now(),
        ]);
    });

    test('gets a single chunk by index', function () {
        $result = $this->handler->execute('document_get_chunks', [
            'document_id' => $this->document->id,
            'start_index' => 2,
        ]);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('Content of chunk 2');
        expect($result->output)->not->toContain('Content of chunk 1');
        expect($result->output)->not->toContain('Content of chunk 3');
    });

    test('gets a range of chunks', function () {
        $result = $this->handler->execute('document_get_chunks', [
            'document_id' => $this->document->id,
            'start_index' => 1,
            'end_index' => 3,
        ]);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('Content of chunk 1');
        expect($result->output)->toContain('Content of chunk 2');
        expect($result->output)->toContain('Content of chunk 3');
        expect($result->output)->not->toContain('Content of chunk 0');
        expect($result->output)->not->toContain('Content of chunk 4');
    });

    test('limits to max 10 chunks', function () {
        // Create 15 chunks (already have 5, add 10 more)
        for ($i = 5; $i < 15; $i++) {
            DocumentChunk::create([
                'document_id' => $this->document->id,
                'chunk_index' => $i,
                'content' => "Content of chunk {$i}",
                'token_count' => 50,
                'start_offset' => $i * 100,
                'end_offset' => ($i + 1) * 100,
                'created_at' => now(),
            ]);
        }

        $result = $this->handler->execute('document_get_chunks', [
            'document_id' => $this->document->id,
            'start_index' => 0,
            'end_index' => 14,
        ]);

        expect($result->success)->toBeTrue();
        // Should only get chunks 0-9 (max 10)
        expect($result->output)->toContain('Content of chunk 0');
        expect($result->output)->toContain('Content of chunk 9');
        expect($result->output)->not->toContain('Content of chunk 10');
    });

    test('returns error for non-attached document', function () {
        $otherDocument = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
        ]);

        $result = $this->handler->execute('document_get_chunks', [
            'document_id' => $otherDocument->id,
            'start_index' => 0,
        ]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('not found');
    });

    test('returns error when document_id is missing', function () {
        $result = $this->handler->execute('document_get_chunks', [
            'start_index' => 0,
        ]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('document_id');
    });

    test('defaults to first chunk when start_index is missing', function () {
        $result = $this->handler->execute('document_get_chunks', [
            'document_id' => $this->document->id,
        ]);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('Content of chunk 0');
    });
});

describe('document_read_file', function () {
    test('reads entire document content', function () {
        $document = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
            'title' => 'Full Read Document',
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'First chunk of content.',
            'token_count' => 30,
            'start_offset' => 0,
            'end_offset' => 100,
            'created_at' => now(),
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Second chunk of content.',
            'token_count' => 30,
            'start_offset' => 100,
            'end_offset' => 200,
            'created_at' => now(),
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 2,
            'content' => 'Third chunk of content.',
            'token_count' => 30,
            'start_offset' => 200,
            'end_offset' => 300,
            'created_at' => now(),
        ]);

        $this->conversation->documents()->attach($document->id, [
            'preview_chunks' => 2,
            'preview_tokens' => 60,
            'attached_at' => now(),
        ]);

        $result = $this->handler->execute('document_read_file', [
            'document_id' => $document->id,
        ]);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('Full Read Document');
        expect($result->output)->toContain('First chunk of content.');
        expect($result->output)->toContain('Second chunk of content.');
        expect($result->output)->toContain('Third chunk of content.');
    });

    test('includes section titles in output', function () {
        $document = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
            'title' => 'Sectioned Document',
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Intro content.',
            'token_count' => 20,
            'start_offset' => 0,
            'end_offset' => 50,
            'section_title' => 'Introduction',
            'created_at' => now(),
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Body content.',
            'token_count' => 20,
            'start_offset' => 50,
            'end_offset' => 100,
            'section_title' => 'Body',
            'created_at' => now(),
        ]);

        $this->conversation->documents()->attach($document->id, [
            'preview_chunks' => 2,
            'preview_tokens' => 40,
            'attached_at' => now(),
        ]);

        $result = $this->handler->execute('document_read_file', [
            'document_id' => $document->id,
        ]);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('## Introduction');
        expect($result->output)->toContain('## Body');
    });

    test('returns error when document exceeds token limit', function () {
        $document = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
            'title' => 'Large Document',
        ]);

        // Create chunks that exceed 50,000 tokens
        for ($i = 0; $i < 60; $i++) {
            DocumentChunk::create([
                'document_id' => $document->id,
                'chunk_index' => $i,
                'content' => str_repeat('word ', 200),
                'token_count' => 1000,
                'start_offset' => $i * 1000,
                'end_offset' => ($i + 1) * 1000,
                'created_at' => now(),
            ]);
        }

        $this->conversation->documents()->attach($document->id, [
            'preview_chunks' => 2,
            'preview_tokens' => 2000,
            'attached_at' => now(),
        ]);

        $result = $this->handler->execute('document_read_file', [
            'document_id' => $document->id,
        ]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('too large');
        expect($result->error)->toContain('document_get_chunks');
    });

    test('returns error for non-attached document', function () {
        $document = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
        ]);

        $result = $this->handler->execute('document_read_file', [
            'document_id' => $document->id,
        ]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('not found');
    });

    test('returns error when document_id is missing', function () {
        $result = $this->handler->execute('document_read_file', []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('document_id');
    });
});

describe('document_search', function () {
    beforeEach(function () {
        $this->document = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
            'title' => 'Searchable Document',
        ]);

        DocumentChunk::create([
            'document_id' => $this->document->id,
            'chunk_index' => 0,
            'content' => 'This chunk contains important information about Laravel.',
            'token_count' => 50,
            'start_offset' => 0,
            'end_offset' => 100,
            'created_at' => now(),
        ]);

        DocumentChunk::create([
            'document_id' => $this->document->id,
            'chunk_index' => 1,
            'content' => 'Another chunk with PHP and database content.',
            'token_count' => 50,
            'start_offset' => 100,
            'end_offset' => 200,
            'created_at' => now(),
        ]);

        DocumentChunk::create([
            'document_id' => $this->document->id,
            'chunk_index' => 2,
            'content' => 'More Laravel framework patterns here.',
            'token_count' => 50,
            'start_offset' => 200,
            'end_offset' => 300,
            'created_at' => now(),
        ]);

        $this->conversation->documents()->attach($this->document->id, [
            'preview_chunks' => 2,
            'preview_tokens' => 100,
            'attached_at' => now(),
        ]);
    });

    test('finds matching chunks', function () {
        $result = $this->handler->execute('document_search', ['query' => 'Laravel']);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('Search results for');
        expect($result->output)->toContain('Laravel');
        expect($result->output)->toContain('Chunk 0');
        expect($result->output)->toContain('Chunk 2');
    });

    test('returns no results message for non-matching query', function () {
        $result = $this->handler->execute('document_search', ['query' => 'nonexistent']);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('No results found');
    });

    test('searches in specific document when document_id provided', function () {
        $otherDocument = Document::factory()->ready()->create([
            'user_id' => $this->user->id,
        ]);

        DocumentChunk::create([
            'document_id' => $otherDocument->id,
            'chunk_index' => 0,
            'content' => 'This also mentions Laravel.',
            'token_count' => 50,
            'start_offset' => 0,
            'end_offset' => 100,
            'created_at' => now(),
        ]);

        $this->conversation->documents()->attach($otherDocument->id, [
            'preview_chunks' => 2,
            'preview_tokens' => 50,
            'attached_at' => now(),
        ]);

        $result = $this->handler->execute('document_search', [
            'query' => 'Laravel',
            'document_id' => $this->document->id,
        ]);

        expect($result->success)->toBeTrue();
        // Should only find in the specified document
        expect($result->output)->toContain('Searchable Document');
    });

    test('respects max_results limit', function () {
        // Add more matching chunks
        for ($i = 3; $i < 10; $i++) {
            DocumentChunk::create([
                'document_id' => $this->document->id,
                'chunk_index' => $i,
                'content' => "Another Laravel mention in chunk {$i}.",
                'token_count' => 50,
                'start_offset' => $i * 100,
                'end_offset' => ($i + 1) * 100,
                'created_at' => now(),
            ]);
        }

        $result = $this->handler->execute('document_search', [
            'query' => 'Laravel',
            'max_results' => 3,
        ]);

        expect($result->success)->toBeTrue();
        // Should respect max_results limit - count result entries by the "[Doc" prefix
        $matchCount = substr_count($result->output, '[Doc');
        expect($matchCount)->toBeLessThanOrEqual(3);
    });

    test('returns error when query is missing', function () {
        $result = $this->handler->execute('document_search', []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('query');
    });

    test('returns error when query is too short', function () {
        $result = $this->handler->execute('document_search', ['query' => 'a']);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('2 characters');
    });
});

describe('unknown tool', function () {
    test('returns error for unknown tool name', function () {
        $result = $this->handler->execute('document_unknown', []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('Unknown document tool');
    });
});
