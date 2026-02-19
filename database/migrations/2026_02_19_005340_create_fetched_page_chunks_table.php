<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    public function up(): void
    {
        Schema::create('fetched_page_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fetched_page_id')->constrained()->cascadeOnDelete();

            // Content
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->unsignedInteger('token_count')->default(0);
            $table->unsignedInteger('start_offset')->default(0);
            $table->unsignedInteger('end_offset')->default(0);
            $table->string('section_title')->nullable();
            $table->string('content_hash', 64)->nullable()->index();

            // Embedding
            $table->json('embedding_raw')->nullable();
            $table->string('embedding_model')->nullable();
            $table->unsignedInteger('embedding_dimensions')->default(1536);
            $table->timestamp('embedding_generated_at')->nullable();

            // Sparse vector (upgraded to JSONB on pgsql)
            $table->json('sparse_vector')->nullable();

            // Quality and usage
            $table->float('quality_score')->default(1.0);
            $table->unsignedInteger('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();

            $table->timestamps();

            $table->index('fetched_page_id');
            $table->index('embedding_generated_at');
            $table->index(['fetched_page_id', 'chunk_index']);
        });

        if ($this->isPostgres()) {
            // Native pgvector column
            DB::statement('ALTER TABLE fetched_page_chunks ADD COLUMN embedding vector(1536)');

            // HNSW index for fast approximate nearest neighbour search
            DB::statement('
                CREATE INDEX idx_fetched_page_chunks_hnsw
                ON fetched_page_chunks
                USING hnsw (embedding vector_cosine_ops)
                WITH (m=16, ef_construction=64)
            ');

            // GIN index for sparse vector lookups
            DB::statement('ALTER TABLE fetched_page_chunks ALTER COLUMN sparse_vector TYPE jsonb USING sparse_vector::jsonb');
            DB::statement('CREATE INDEX idx_fetched_page_chunks_sparse ON fetched_page_chunks USING GIN (sparse_vector jsonb_path_ops)');

            // Full-text search index on content
            DB::statement("CREATE INDEX idx_fetched_page_chunks_fts ON fetched_page_chunks USING GIN (to_tsvector('english', content))");
        }
    }

    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS idx_fetched_page_chunks_fts');
            DB::statement('DROP INDEX IF EXISTS idx_fetched_page_chunks_sparse');
            DB::statement('DROP INDEX IF EXISTS idx_fetched_page_chunks_hnsw');
        }

        Schema::dropIfExists('fetched_page_chunks');
    }
};
