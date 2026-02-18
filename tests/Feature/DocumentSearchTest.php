<?php

use App\DTOs\RetrievalResult;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use App\Services\RAG\RAGPipeline;
use App\Services\RAG\RAGPipelineResult;

use function Pest\Laravel\mock;

describe('Document Search API — unauthenticated', function () {
    test('unauthenticated users cannot search documents', function () {
        $this->postJson('/api/v1/documents/search', ['query' => 'hello'])
            ->assertUnauthorized();
    });
});

describe('Document Search API', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    });

    describe('Validation', function () {
        test('query is required', function () {
            $this->postJson('/api/v1/documents/search', [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['query']);
        });

        test('query must be at least 2 characters', function () {
            $this->postJson('/api/v1/documents/search', ['query' => 'a'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['query']);
        });

        test('document_id must exist', function () {
            $this->postJson('/api/v1/documents/search', [
                'query' => 'hello',
                'document_id' => 99999,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['document_id']);
        });

        test('max_results must be between 1 and 10', function (int $value) {
            $this->postJson('/api/v1/documents/search', [
                'query' => 'hello',
                'max_results' => $value,
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['max_results']);
        })->with([0, 11, -1]);
    });

    describe('Search across all user documents', function () {
        test('returns empty results when user has no documents', function () {
            $response = $this->postJson('/api/v1/documents/search', ['query' => 'hello world']);

            $response->assertSuccessful()
                ->assertJson([
                    'query' => 'hello world',
                    'strategy' => 'none',
                    'results' => [],
                    'count' => 0,
                ]);
        });

        test('returns matching chunks with correct structure', function () {
            $document = Document::factory()->ready()->create(['user_id' => $this->user->id, 'title' => 'PHP Basics']);
            DocumentChunk::factory()->withContent('PHP is a popular scripting language for web development')->create([
                'document_id' => $document->id,
            ]);

            $response = $this->postJson('/api/v1/documents/search', [
                'query' => 'scripting language',
                'max_results' => 5,
            ]);

            $response->assertSuccessful()
                ->assertJsonStructure([
                    'query',
                    'strategy',
                    'results' => [
                        '*' => [
                            'document_id',
                            'document_title',
                            'chunk_index',
                            'score',
                            'preview',
                            'section_title',
                        ],
                    ],
                    'count',
                ])
                ->assertJson(['query' => 'scripting language'])
                ->assertJsonPath('count', 1)
                ->assertJsonPath('results.0.document_title', 'PHP Basics');
        });

        test('only searches documents owned by the authenticated user', function () {
            $otherUser = User::factory()->create();
            $otherDocument = Document::factory()->ready()->create(['user_id' => $otherUser->id]);
            DocumentChunk::factory()->withContent('scripting language content')->create([
                'document_id' => $otherDocument->id,
            ]);

            $response = $this->postJson('/api/v1/documents/search', ['query' => 'scripting language']);

            $response->assertSuccessful()
                ->assertJson(['count' => 0]);
        });

        test('respects max_results limit', function () {
            $document = Document::factory()->ready()->create(['user_id' => $this->user->id]);
            DocumentChunk::factory()->count(8)->withContent('scripting language web development')->create([
                'document_id' => $document->id,
            ]);

            $response = $this->postJson('/api/v1/documents/search', [
                'query' => 'scripting language',
                'max_results' => 3,
            ]);

            $response->assertSuccessful();
            expect($response->json('count'))->toBeLessThanOrEqual(3);
        });
    });

    describe('Scoped search by document_id', function () {
        test('can scope search to a specific document', function () {
            $documentA = Document::factory()->ready()->create(['user_id' => $this->user->id, 'title' => 'Doc A']);
            $documentB = Document::factory()->ready()->create(['user_id' => $this->user->id, 'title' => 'Doc B']);

            DocumentChunk::factory()->withContent('scripting language in document A')->create([
                'document_id' => $documentA->id,
            ]);
            DocumentChunk::factory()->withContent('scripting language in document B')->create([
                'document_id' => $documentB->id,
            ]);

            $response = $this->postJson('/api/v1/documents/search', [
                'query' => 'scripting language',
                'document_id' => $documentA->id,
            ]);

            $response->assertSuccessful();

            $results = $response->json('results');
            expect(collect($results)->pluck('document_id')->unique()->all())->toBe([$documentA->id]);
        });

        test('returns 403 when document_id belongs to another user', function () {
            $otherUser = User::factory()->create();
            $otherDocument = Document::factory()->ready()->create(['user_id' => $otherUser->id]);

            $this->postJson('/api/v1/documents/search', [
                'query' => 'hello world',
                'document_id' => $otherDocument->id,
            ])->assertForbidden();
        });
    });

    describe('RAG pipeline search', function () {
        test('uses RAG results when pipeline succeeds', function () {
            $document = Document::factory()->ready()->create(['user_id' => $this->user->id, 'title' => 'RAG Doc']);
            $chunk = DocumentChunk::factory()->withContent('RAG retrieved content')->create([
                'document_id' => $document->id,
            ]);
            $chunk->load('document');

            $retrieval = new RetrievalResult(
                chunks: collect([$chunk]),
                strategy: 'vector',
                scores: [$chunk->id => 0.9512],
                executionTimeMs: 10.0,
            );

            $ragResult = new RAGPipelineResult(
                context: 'RAG retrieved content',
                retrieval: $retrieval,
                citations: [],
                executionTimeMs: 12.0,
            );

            mock(RAGPipeline::class)
                ->shouldReceive('execute')
                ->once()
                ->andReturn($ragResult);

            $response = $this->postJson('/api/v1/documents/search', ['query' => 'RAG content']);

            $response->assertSuccessful()
                ->assertJson([
                    'query' => 'RAG content',
                    'strategy' => 'vector',
                    'count' => 1,
                ]);

            $result = $response->json('results.0');
            expect($result['score'])->toBe(0.9512)
                ->and($result['document_title'])->toBe('RAG Doc');
        });
    });
});
