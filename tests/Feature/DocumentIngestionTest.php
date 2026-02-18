<?php

use App\Enums\DocumentStageType;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use App\Services\Document\DocumentIngestionService;
use App\Services\Document\TextExtractorRegistry;
use App\Services\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('Document Ingestion API', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
        Storage::fake('local');
    });

    describe('List Documents', function () {
        test('user can list their own documents', function () {
            Document::factory()->count(3)->create(['user_id' => $this->user->id]);
            Document::factory()->count(2)->create(); // Other user's documents

            $response = $this->getJson('/api/v1/documents');

            $response->assertStatus(200)
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'title',
                            'source_type',
                            'mime_type',
                            'file_size',
                            'status',
                            'created_at',
                        ],
                    ],
                ]);
        });

        test('user can filter documents by status', function () {
            Document::factory()->count(2)->create(['user_id' => $this->user->id, 'status' => DocumentStatus::Ready]);
            Document::factory()->create(['user_id' => $this->user->id, 'status' => DocumentStatus::Pending]);

            $response = $this->getJson('/api/v1/documents?status=ready');

            $response->assertStatus(200)
                ->assertJsonCount(2, 'data');
        });

        test('user can search documents by title', function () {
            Document::factory()->create(['user_id' => $this->user->id, 'title' => 'Important Report']);
            Document::factory()->create(['user_id' => $this->user->id, 'title' => 'Meeting Notes']);

            $response = $this->getJson('/api/v1/documents?search=Report');

            $response->assertStatus(200)
                ->assertJsonCount(1, 'data');
        });
    });

    describe('Store Document', function () {
        test('user can ingest document from file upload', function () {
            $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

            $response = $this->postJson('/api/v1/documents', [
                'source_type' => 'upload',
                'title' => 'Test Document',
                'file' => $file,
            ]);

            $response->assertStatus(201)
                ->assertJsonFragment([
                    'title' => 'Test Document',
                    'source_type' => 'upload',
                    'status' => 'pending',
                ]);

            $this->assertDatabaseHas('documents', [
                'title' => 'Test Document',
                'user_id' => $this->user->id,
            ]);
        });

        test('user can ingest document from pasted text', function () {
            $response = $this->postJson('/api/v1/documents', [
                'source_type' => 'paste',
                'title' => 'Pasted Content',
                'text' => 'This is some pasted text content for testing.',
            ]);

            $response->assertStatus(201)
                ->assertJsonFragment([
                    'title' => 'Pasted Content',
                    'source_type' => 'paste',
                    'mime_type' => 'text/plain',
                ]);
        });

        test('document creation fails without source_type', function () {
            $response = $this->postJson('/api/v1/documents', [
                'title' => 'Test Document',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['source_type']);
        });

        test('document creation fails with invalid source_type', function () {
            $response = $this->postJson('/api/v1/documents', [
                'source_type' => 'invalid',
                'title' => 'Test Document',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['source_type']);
        });

        test('file upload fails without file when source_type is upload', function () {
            $response = $this->postJson('/api/v1/documents', [
                'source_type' => 'upload',
                'title' => 'Test Document',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['file']);
        });

        test('text paste fails without text when source_type is paste', function () {
            $response = $this->postJson('/api/v1/documents', [
                'source_type' => 'paste',
                'title' => 'Test Document',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['text']);
        });

        test('url ingestion creates a persistent file record', function () {
            Queue::fake();

            $tempPath = tempnam(sys_get_temp_dir(), 'test_doc_');
            file_put_contents($tempPath, 'test document content');

            $service = new class(app(TextExtractorRegistry::class), app(FileService::class)) extends DocumentIngestionService
            {
                public string $fakeTempPath;

                protected function downloadFromUrl(string $url): string
                {
                    return $this->fakeTempPath;
                }
            };
            $service->fakeTempPath = $tempPath;
            $this->app->instance(DocumentIngestionService::class, $service);

            $response = $this->postJson('/api/v1/documents', [
                'source_type' => 'url',
                'title' => 'URL Test Document',
                'url' => 'https://example.com/doc.txt',
            ]);

            $response->assertStatus(201)
                ->assertJsonFragment([
                    'title' => 'URL Test Document',
                    'source_type' => 'url',
                ]);

            $this->assertDatabaseHas('files', [
                'user_id' => $this->user->id,
                'type' => 'input',
            ]);

            $document = Document::where('title', 'URL Test Document')->first();
            expect($document->file_id)->not->toBeNull()
                ->and($document->source_path)->toBe('https://example.com/doc.txt')
                ->and($document->metadata['original_url'])->toBe('https://example.com/doc.txt');
        });
    });

    describe('Show Document', function () {
        test('user can view their own document', function () {
            $document = Document::factory()->create(['user_id' => $this->user->id]);

            $response = $this->getJson("/api/v1/documents/{$document->id}");

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'id' => $document->id,
                    'title' => $document->title,
                ]);
        });

        test('user cannot view another user\'s document', function () {
            $otherDocument = Document::factory()->create();

            $response = $this->getJson("/api/v1/documents/{$otherDocument->id}");

            $response->assertStatus(403);
        });

        test('returns 404 for non-existent document', function () {
            $response = $this->getJson('/api/v1/documents/99999');

            $response->assertStatus(404);
        });
    });

    describe('Delete Document', function () {
        test('user can delete their own document', function () {
            $document = Document::factory()->create(['user_id' => $this->user->id]);

            $response = $this->deleteJson("/api/v1/documents/{$document->id}");

            $response->assertStatus(204);

            $this->assertSoftDeleted('documents', [
                'id' => $document->id,
            ]);
        });

        test('user cannot delete another user\'s document', function () {
            $otherDocument = Document::factory()->create();

            $response = $this->deleteJson("/api/v1/documents/{$otherDocument->id}");

            $response->assertStatus(403);
        });
    });

    describe('Reprocess Document', function () {
        test('user can reprocess their own document', function () {
            Queue::fake();

            $document = Document::factory()->ready()->create(['user_id' => $this->user->id]);

            $response = $this->postJson("/api/v1/documents/{$document->id}/reprocess");

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'status' => 'pending',
                ]);
        });

        test('user cannot reprocess another user\'s document', function () {
            $otherDocument = Document::factory()->create();

            $response = $this->postJson("/api/v1/documents/{$otherDocument->id}/reprocess");

            $response->assertStatus(403);
        });
    });

    describe('Document Stages', function () {
        test('user can view document stages', function () {
            $document = Document::factory()->create(['user_id' => $this->user->id]);
            $document->stages()->create([
                'stage' => DocumentStageType::Extracted,
                'content' => 'Extracted text content',
                'metadata' => ['char_count' => 22],
                'created_at' => now(),
            ]);

            $response = $this->getJson("/api/v1/documents/{$document->id}/stages");

            $response->assertStatus(200)
                ->assertJsonCount(1)
                ->assertJsonFragment([
                    'stage' => 'extracted',
                ]);
        });
    });

    describe('Document Chunks', function () {
        test('user can view document chunks', function () {
            $document = Document::factory()->ready()->create(['user_id' => $this->user->id]);
            $document->chunks()->createMany([
                [
                    'chunk_index' => 0,
                    'content' => 'First chunk content',
                    'token_count' => 100,
                    'start_offset' => 0,
                    'end_offset' => 19,
                    'created_at' => now(),
                ],
                [
                    'chunk_index' => 1,
                    'content' => 'Second chunk content',
                    'token_count' => 100,
                    'start_offset' => 20,
                    'end_offset' => 40,
                    'created_at' => now(),
                ],
            ]);

            $response = $this->getJson("/api/v1/documents/{$document->id}/chunks");

            $response->assertStatus(200)
                ->assertJsonCount(2, 'data');
        });
    });

    describe('Document Preview', function () {
        test('user can view document preview', function () {
            $document = Document::factory()->ready()->create(['user_id' => $this->user->id]);

            $response = $this->getJson("/api/v1/documents/{$document->id}/preview");

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'document',
                    'original_preview',
                    'cleaned_preview',
                    'sample_chunks',
                    'total_chunks',
                    'total_tokens',
                ]);
        });
    });

    describe('Supported Types', function () {
        test('user can get supported MIME types', function () {
            $response = $this->getJson('/api/v1/documents/supported-types');

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'supported_types',
                ]);
        });
    });
});

describe('Document Ingestion API - Unauthenticated', function () {
    test('unauthenticated user cannot list documents', function () {
        $response = $this->getJson('/api/v1/documents');
        $response->assertStatus(401);
    });

    test('unauthenticated user cannot create documents', function () {
        $response = $this->postJson('/api/v1/documents', [
            'source_type' => 'paste',
            'text' => 'Test content',
        ]);
        $response->assertStatus(401);
    });
});
