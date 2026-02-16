<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Check if we're using PostgreSQL.
     */
    protected function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enable pgvector extension (PostgreSQL only)
        if ($this->isPostgres()) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        // Add vector-related columns to existing document_chunks table
        Schema::table('document_chunks', function (Blueprint $table) {
            // Chunk type for different chunking strategies
            $table->string('chunk_type')->default('standard')->after('section_title');

            // Headers breadcrumb for navigation
            $table->json('headers')->nullable()->after('chunk_type');

            // Embedding metadata
            $table->unsignedInteger('embedding_dimensions')->default(1536)->after('metadata');
            $table->json('embedding_raw')->nullable()->after('embedding_dimensions');
            $table->string('embedding_model')->nullable()->after('embedding_raw');
            $table->timestamp('embedding_generated_at')->nullable()->after('embedding_model');

            // Sparse vector for hybrid search
            $table->json('sparse_vector')->nullable()->after('embedding_generated_at');

            // Quality and ranking
            $table->float('quality_score')->default(1.0)->after('sparse_vector');
            $table->unsignedInteger('access_count')->default(0)->after('quality_score');
            $table->timestamp('last_accessed_at')->nullable()->after('access_count');

            // Additional metadata
            $table->string('source_type')->default('document')->after('last_accessed_at');
            $table->string('language')->default('en')->after('source_type');
            $table->string('content_hash', 64)->nullable()->after('language');

            // Add updated_at if not exists
            $table->timestamp('updated_at')->nullable()->after('created_at');

            // New indexes
            $table->index('chunk_type');
            $table->index('language');
            $table->index('embedding_generated_at');
            $table->index('content_hash');
        });

        // PostgreSQL-specific: Add native pgvector column and indexes
        if ($this->isPostgres()) {
            // Add native vector column for efficient similarity search
            DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(1536)');

            // Create HNSW index for fast approximate nearest neighbor search
            DB::statement('
                CREATE INDEX idx_chunks_embedding_hnsw
                ON document_chunks
                USING hnsw (embedding vector_cosine_ops)
                WITH (m=16, ef_construction=64)
            ');

            // Create GIN index for sparse vector (keyword matching) - requires jsonb
            DB::statement('ALTER TABLE document_chunks ALTER COLUMN sparse_vector TYPE jsonb USING sparse_vector::jsonb');
            DB::statement('CREATE INDEX idx_chunks_sparse_vector ON document_chunks USING GIN (sparse_vector jsonb_path_ops)');

            // Create full-text search index on content
            DB::statement("CREATE INDEX idx_chunks_content_fts ON document_chunks USING GIN (to_tsvector('english', content))");
        }

        // Embedding cache table for deduplication
        Schema::create('embedding_cache', function (Blueprint $table) {
            $table->id();
            $table->string('content_hash', 64);
            $table->json('embedding_raw')->nullable();
            $table->string('embedding_model');
            $table->string('language')->default('en');
            $table->timestamps();

            $table->unique(['content_hash', 'embedding_model', 'language']);
            $table->index('content_hash');
        });

        // PostgreSQL-specific: Add native vector column to cache
        if ($this->isPostgres()) {
            DB::statement('ALTER TABLE embedding_cache ADD COLUMN embedding vector(1536)');
        }

        // Retrieval logging for analytics
        Schema::create('retrieval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('query');
            $table->json('query_expansion')->nullable();

            $table->json('retrieved_chunks')->nullable();
            $table->string('retrieval_strategy');

            $table->json('retrieval_scores')->nullable();
            $table->float('execution_time_ms');

            $table->unsignedInteger('chunks_found')->default(0);
            $table->float('average_score')->nullable();
            $table->boolean('user_found_helpful')->nullable();

            $table->timestamps();

            $table->index('conversation_id');
            $table->index('user_id');
            $table->index('created_at');
            $table->index('retrieval_strategy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retrieval_logs');
        Schema::dropIfExists('embedding_cache');

        // Remove pgvector specific indexes and columns
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS idx_chunks_content_fts');
            DB::statement('DROP INDEX IF EXISTS idx_chunks_sparse_vector');
            DB::statement('DROP INDEX IF EXISTS idx_chunks_embedding_hnsw');
            DB::statement('ALTER TABLE document_chunks DROP COLUMN IF EXISTS embedding');
        }

        // Remove added columns from document_chunks
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropIndex(['chunk_type']);
            $table->dropIndex(['language']);
            $table->dropIndex(['embedding_generated_at']);
            $table->dropIndex(['content_hash']);

            $table->dropColumn([
                'chunk_type',
                'headers',
                'embedding_dimensions',
                'embedding_raw',
                'embedding_model',
                'embedding_generated_at',
                'sparse_vector',
                'quality_score',
                'access_count',
                'last_accessed_at',
                'source_type',
                'language',
                'content_hash',
                'updated_at',
            ]);
        });
    }
};
