<?php

use App\Enums\EmbeddingStatus;
use App\Jobs\GenerateEmbeddingJob;
use App\Models\Embedding;
use App\Models\User;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;

describe('Embedding Management', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
        Config::set('ai.rag.enabled', true);
        Config::set('ai.rag.embedding_model', 'qwen3-embedding:4b');
        Config::set('ai.rag.embedding_backend', 'ollama');
    });

    describe('List Embeddings', function () {
        test('user can list their own embeddings', function () {
            Embedding::factory()->count(3)->create(['user_id' => $this->user->id]);
            Embedding::factory()->count(2)->create(); // Other user's embeddings

            $response = $this->getJson('/api/v1/embeddings');

            $response->assertStatus(200)
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'text',
                            'status',
                            'model',
                            'created_at',
                            'updated_at',
                        ],
                    ],
                ]);
        });

        test('returns paginated results', function () {
            Embedding::factory()->count(20)->create(['user_id' => $this->user->id]);

            $response = $this->getJson('/api/v1/embeddings?per_page=5');

            $response->assertStatus(200)
                ->assertJsonCount(5, 'data')
                ->assertJsonStructure([
                    'data',
                    'links',
                    'meta' => [
                        'current_page',
                        'per_page',
                        'total',
                    ],
                ]);
        });

        test('can filter by status', function () {
            Embedding::factory()->completed()->count(3)->create(['user_id' => $this->user->id]);
            Embedding::factory()->pending()->count(2)->create(['user_id' => $this->user->id]);
            Embedding::factory()->failed()->count(1)->create(['user_id' => $this->user->id]);

            $response = $this->getJson('/api/v1/embeddings?status=completed');

            $response->assertStatus(200)
                ->assertJsonCount(3, 'data');

            $response->json('data', function ($embeddings) {
                foreach ($embeddings as $embedding) {
                    expect($embedding['status'])->toBe('completed');
                }
            });
        });

        test('embeddings are ordered by most recent first', function () {
            $oldest = Embedding::factory()->create([
                'user_id' => $this->user->id,
                'created_at' => now()->subDays(2),
            ]);
            $newest = Embedding::factory()->create([
                'user_id' => $this->user->id,
                'created_at' => now(),
            ]);

            $response = $this->getJson('/api/v1/embeddings');

            $response->assertStatus(200);
            $data = $response->json('data');
            expect($data[0]['id'])->toBe($newest->id);
            expect($data[1]['id'])->toBe($oldest->id);
        });

        test('does not include other users embeddings', function () {
            $otherUser = User::factory()->create();
            Embedding::factory()->count(5)->create(['user_id' => $otherUser->id]);
            Embedding::factory()->count(2)->create(['user_id' => $this->user->id]);

            $response = $this->getJson('/api/v1/embeddings');

            $response->assertStatus(200)
                ->assertJsonCount(2, 'data');
        });
    });

    describe('Generate Embeddings (Sync)', function () {
        test('can generate single embedding', function () {
            $mockService = Mockery::mock(EmbeddingService::class);
            $mockService->shouldReceive('embed')
                ->once()
                ->with('Hello world', 'qwen3-embedding:4b')
                ->andReturn(array_fill(0, 1536, 0.5));

            $this->app->instance(EmbeddingService::class, $mockService);

            $response = $this->postJson('/api/v1/embeddings', [
                'input' => 'Hello world',
            ]);

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'object',
                    'data' => [
                        '*' => [
                            'object',
                            'index',
                            'embedding',
                        ],
                    ],
                    'model',
                    'usage',
                ])
                ->assertJson([
                    'object' => 'list',
                    'model' => 'qwen3-embedding:4b',
                ]);

            expect($response->json('data'))->toHaveCount(1);
            expect($response->json('data.0.index'))->toBe(0);
            expect($response->json('data.0.embedding'))->toBeArray();
        });

        test('can generate batch embeddings', function () {
            $mockService = Mockery::mock(EmbeddingService::class);
            $mockService->shouldReceive('embedBatch')
                ->once()
                ->with(['First text', 'Second text'], 'qwen3-embedding:4b')
                ->andReturn([
                    array_fill(0, 1536, 0.5),
                    array_fill(0, 1536, 0.6),
                ]);

            $this->app->instance(EmbeddingService::class, $mockService);

            $response = $this->postJson('/api/v1/embeddings', [
                'input' => ['First text', 'Second text'],
            ]);

            $response->assertStatus(200)
                ->assertJson([
                    'object' => 'list',
                    'model' => 'qwen3-embedding:4b',
                ]);

            expect($response->json('data'))->toHaveCount(2);
            expect($response->json('data.0.index'))->toBe(0);
            expect($response->json('data.1.index'))->toBe(1);
        });

        test('validates input is required', function () {
            $response = $this->postJson('/api/v1/embeddings', []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['input']);
        });

        test('validates input max length', function () {
            $response = $this->postJson('/api/v1/embeddings', [
                'input' => str_repeat('a', 8193),
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['input.0']);
        });

        test('validates input min length', function () {
            $response = $this->postJson('/api/v1/embeddings', [
                'input' => [''],
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['input.0']);
        });

        test('validates each item in batch input', function () {
            $response = $this->postJson('/api/v1/embeddings', [
                'input' => ['Valid text', str_repeat('a', 8193)],
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['input.1']);
        });

        test('returns 400 when RAG is disabled', function () {
            Config::set('ai.rag.enabled', false);

            $response = $this->postJson('/api/v1/embeddings', [
                'input' => 'Hello world',
            ]);

            $response->assertStatus(400)
                ->assertJson([
                    'error' => 'Embedding service is not enabled',
                ]);
        });

        test('accepts custom model parameter', function () {
            $mockService = Mockery::mock(EmbeddingService::class);
            $mockService->shouldReceive('embed')
                ->once()
                ->with('Hello world', 'custom-model:1b')
                ->andReturn(array_fill(0, 1536, 0.5));

            $this->app->instance(EmbeddingService::class, $mockService);

            $response = $this->postJson('/api/v1/embeddings', [
                'input' => 'Hello world',
                'model' => 'custom-model:1b',
            ]);

            $response->assertStatus(200)
                ->assertJson([
                    'model' => 'custom-model:1b',
                ]);
        });

        test('handles service exceptions gracefully', function () {
            $mockService = Mockery::mock(EmbeddingService::class);
            $mockService->shouldReceive('embed')
                ->andThrow(new \Exception('Service unavailable'));

            $this->app->instance(EmbeddingService::class, $mockService);

            $response = $this->postJson('/api/v1/embeddings', [
                'input' => 'Hello world',
            ]);

            $response->assertStatus(500)
                ->assertJson([
                    'error' => 'Failed to generate embeddings',
                    'message' => 'Service unavailable',
                ]);
        });
    });

    describe('Generate Embedding (Async)', function () {
        test('creates embedding with pending status', function () {
            Queue::fake();

            $response = $this->postJson('/api/v1/embeddings/async', [
                'text' => 'Hello async world',
            ]);

            $response->assertStatus(202)
                ->assertJsonStructure([
                    'id',
                    'text',
                    'status',
                    'model',
                    'created_at',
                ])
                ->assertJsonFragment([
                    'status' => 'pending',
                    'text' => 'Hello async world',
                ]);

            $this->assertDatabaseHas('embeddings', [
                'user_id' => $this->user->id,
                'text' => 'Hello async world',
                'status' => EmbeddingStatus::Pending->value,
            ]);
        });

        test('dispatches GenerateEmbeddingJob', function () {
            Queue::fake();

            $response = $this->postJson('/api/v1/embeddings/async', [
                'text' => 'Hello async world',
            ]);

            $response->assertStatus(202);

            Queue::assertPushed(GenerateEmbeddingJob::class, function ($job) {
                return $job->embedding->text === 'Hello async world';
            });
        });

        test('generates text hash correctly', function () {
            Queue::fake();

            $text = 'Test text for hashing';
            $model = 'qwen3-embedding:4b';
            $expectedHash = Embedding::hashText($text, $model);

            $response = $this->postJson('/api/v1/embeddings/async', [
                'text' => $text,
            ]);

            $response->assertStatus(202);

            $this->assertDatabaseHas('embeddings', [
                'text' => $text,
                'text_hash' => $expectedHash,
            ]);
        });

        test('validates text is required', function () {
            $response = $this->postJson('/api/v1/embeddings/async', []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['text']);
        });

        test('validates text max length', function () {
            $response = $this->postJson('/api/v1/embeddings/async', [
                'text' => str_repeat('a', 8193),
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['text']);
        });

        test('validates text min length', function () {
            $response = $this->postJson('/api/v1/embeddings/async', [
                'text' => '',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['text']);
        });

        test('returns 400 when RAG is disabled', function () {
            Config::set('ai.rag.enabled', false);

            $response = $this->postJson('/api/v1/embeddings/async', [
                'text' => 'Hello world',
            ]);

            $response->assertStatus(400)
                ->assertJson([
                    'error' => 'Embedding service is not enabled',
                ]);
        });

        test('accepts custom model parameter', function () {
            Queue::fake();

            $response = $this->postJson('/api/v1/embeddings/async', [
                'text' => 'Hello world',
                'model' => 'custom-model:1b',
            ]);

            $response->assertStatus(202);

            $this->assertDatabaseHas('embeddings', [
                'text' => 'Hello world',
                'model' => 'custom-model:1b',
            ]);
        });
    });

    describe('Show Embedding', function () {
        test('user can view their own embedding', function () {
            $embedding = Embedding::factory()->completed()->create(['user_id' => $this->user->id]);

            $response = $this->getJson("/api/v1/embeddings/{$embedding->id}");

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'id' => $embedding->id,
                    'text' => $embedding->text,
                    'status' => 'completed',
                ]);
        });

        test('user cannot view another users embedding', function () {
            $otherEmbedding = Embedding::factory()->create();

            $response = $this->getJson("/api/v1/embeddings/{$otherEmbedding->id}");

            $response->assertStatus(403);
        });

        test('returns 404 for non-existent embedding', function () {
            $response = $this->getJson('/api/v1/embeddings/99999');

            $response->assertStatus(404);
        });

        test('can poll for pending embedding status', function () {
            $embedding = Embedding::factory()->pending()->create(['user_id' => $this->user->id]);

            $response = $this->getJson("/api/v1/embeddings/{$embedding->id}");

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'status' => 'pending',
                ]);
        });

        test('can poll for processing embedding status', function () {
            $embedding = Embedding::factory()->processing()->create(['user_id' => $this->user->id]);

            $response = $this->getJson("/api/v1/embeddings/{$embedding->id}");

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'status' => 'processing',
                ]);
        });

        test('can view completed embedding with vector data', function () {
            $embedding = Embedding::factory()->completed()->create(['user_id' => $this->user->id]);

            $response = $this->getJson("/api/v1/embeddings/{$embedding->id}");

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'status' => 'completed',
                ]);

            expect($response->json('dimensions'))->toBe(1536);
        });

        test('can view failed embedding with error', function () {
            $embedding = Embedding::factory()->failed()->create(['user_id' => $this->user->id]);

            $response = $this->getJson("/api/v1/embeddings/{$embedding->id}");

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'status' => 'failed',
                ]);

            expect($response->json('error'))->toBeString();
        });
    });

    describe('Delete Embedding', function () {
        test('user can delete their own embedding', function () {
            $embedding = Embedding::factory()->create(['user_id' => $this->user->id]);

            $response = $this->deleteJson("/api/v1/embeddings/{$embedding->id}");

            $response->assertStatus(204);

            $this->assertDatabaseMissing('embeddings', [
                'id' => $embedding->id,
            ]);
        });

        test('user cannot delete another users embedding', function () {
            $otherEmbedding = Embedding::factory()->create();

            $response = $this->deleteJson("/api/v1/embeddings/{$otherEmbedding->id}");

            $response->assertStatus(403);

            $this->assertDatabaseHas('embeddings', [
                'id' => $otherEmbedding->id,
            ]);
        });

        test('returns 404 for non-existent embedding', function () {
            $response = $this->deleteJson('/api/v1/embeddings/99999');

            $response->assertStatus(404);
        });

        test('can delete pending embedding', function () {
            $embedding = Embedding::factory()->pending()->create(['user_id' => $this->user->id]);

            $response = $this->deleteJson("/api/v1/embeddings/{$embedding->id}");

            $response->assertStatus(204);
        });

        test('can delete failed embedding', function () {
            $embedding = Embedding::factory()->failed()->create(['user_id' => $this->user->id]);

            $response = $this->deleteJson("/api/v1/embeddings/{$embedding->id}");

            $response->assertStatus(204);
        });
    });

    describe('List Embedding Models', function () {
        test('returns list of embedding models', function () {
            $this->mock(\App\Services\AIBackendManager::class, function ($mock) {
                $backend = Mockery::mock(\App\Contracts\AIBackendInterface::class);
                $backend->shouldReceive('listModels')
                    ->andReturn([
                        ['name' => 'qwen3-embedding:4b', 'size' => 2500000000],
                        ['name' => 'nomic-embed-text', 'size' => 274000000],
                        ['name' => 'qwen3:8b', 'size' => 5000000000], // Not an embedding model
                    ]);

                $mock->shouldReceive('driver')
                    ->andReturn($backend);
            });

            $response = $this->getJson('/api/v1/embeddings/models');

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'models' => [
                        '*' => ['name', 'size'],
                    ],
                    'default_model',
                ])
                ->assertJson([
                    'default_model' => 'qwen3-embedding:4b',
                ]);

            $models = $response->json('models');
            expect($models)->toHaveCount(2);
            expect(collect($models)->pluck('name')->all())->toContain('qwen3-embedding:4b', 'nomic-embed-text');
        });

        test('filters models to only embedding models', function () {
            $this->mock(\App\Services\AIBackendManager::class, function ($mock) {
                $backend = Mockery::mock(\App\Contracts\AIBackendInterface::class);
                $backend->shouldReceive('listModels')
                    ->andReturn([
                        ['name' => 'all-minilm-embedding', 'size' => 1000000],
                        ['name' => 'llama3:8b', 'size' => 5000000000],
                        ['name' => 'mxbai-embed-large', 'size' => 668000000],
                        ['name' => 'mistral:7b', 'size' => 4000000000],
                    ]);

                $mock->shouldReceive('driver')
                    ->andReturn($backend);
            });

            $response = $this->getJson('/api/v1/embeddings/models');

            $response->assertStatus(200);

            $models = $response->json('models');
            expect($models)->toHaveCount(2);

            $names = collect($models)->pluck('name')->all();
            expect($names)->toContain('all-minilm-embedding', 'mxbai-embed-large');
            expect($names)->not->toContain('llama3:8b', 'mistral:7b');
        });

        test('returns 400 when RAG is disabled', function () {
            Config::set('ai.rag.enabled', false);

            $response = $this->getJson('/api/v1/embeddings/models');

            $response->assertStatus(400)
                ->assertJson([
                    'error' => 'Embedding service is not enabled',
                ]);
        });

        test('handles backend exceptions gracefully', function () {
            $this->mock(\App\Services\AIBackendManager::class, function ($mock) {
                $backend = Mockery::mock(\App\Contracts\AIBackendInterface::class);
                $backend->shouldReceive('listModels')
                    ->andThrow(new \Exception('Backend unavailable'));

                $mock->shouldReceive('driver')
                    ->andReturn($backend);
            });

            $response = $this->getJson('/api/v1/embeddings/models');

            $response->assertStatus(500)
                ->assertJson([
                    'error' => 'Failed to list models',
                    'message' => 'Backend unavailable',
                ]);
        });
    });

    describe('Get Embedding Configuration', function () {
        test('returns current embedding configuration', function () {
            Config::set('ai.rag.enabled', true);
            Config::set('ai.rag.embedding_model', 'qwen3-embedding:4b');
            Config::set('ai.rag.embedding_backend', 'ollama');
            Config::set('ai.rag.embedding_dimensions', 30000);
            Config::set('ai.rag.embedding_batch_size', 100);
            Config::set('ai.rag.cache_embeddings', true);

            $response = $this->getJson('/api/v1/embeddings/config');

            $response->assertStatus(200)
                ->assertJson([
                    'enabled' => true,
                    'model' => 'qwen3-embedding:4b',
                    'backend' => 'ollama',
                    'dimensions' => 30000,
                    'batch_size' => 100,
                    'caching_enabled' => true,
                ]);
        });

        test('works even when RAG is disabled', function () {
            Config::set('ai.rag.enabled', false);

            $response = $this->getJson('/api/v1/embeddings/config');

            $response->assertStatus(200)
                ->assertJson([
                    'enabled' => false,
                ]);
        });

        test('returns null values when config is not set', function () {
            Config::set('ai.rag.enabled', false);
            Config::set('ai.rag.embedding_model', null);
            Config::set('ai.rag.embedding_backend', null);

            $response = $this->getJson('/api/v1/embeddings/config');

            $response->assertStatus(200)
                ->assertJson([
                    'enabled' => false,
                    'model' => null,
                    'backend' => null,
                ]);
        });
    });
});

describe('Embedding Management - Unauthenticated', function () {
    test('unauthenticated user cannot list embeddings', function () {
        $response = $this->getJson('/api/v1/embeddings');
        $response->assertStatus(401);
    });

    test('unauthenticated user cannot generate sync embeddings', function () {
        $response = $this->postJson('/api/v1/embeddings', [
            'input' => 'Hello world',
        ]);
        $response->assertStatus(401);
    });

    test('unauthenticated user cannot generate async embeddings', function () {
        $response = $this->postJson('/api/v1/embeddings/async', [
            'text' => 'Hello world',
        ]);
        $response->assertStatus(401);
    });

    test('unauthenticated user cannot view embedding', function () {
        $embedding = Embedding::factory()->create();

        $response = $this->getJson("/api/v1/embeddings/{$embedding->id}");
        $response->assertStatus(401);
    });

    test('unauthenticated user cannot delete embedding', function () {
        $embedding = Embedding::factory()->create();

        $response = $this->deleteJson("/api/v1/embeddings/{$embedding->id}");
        $response->assertStatus(401);
    });

    test('unauthenticated user cannot list models', function () {
        $response = $this->getJson('/api/v1/embeddings/models');
        $response->assertStatus(401);
    });

    test('unauthenticated user cannot get config', function () {
        $response = $this->getJson('/api/v1/embeddings/config');
        $response->assertStatus(401);
    });
});
