<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

describe('Database Schema', function () {
    test('agents table has correct structure', function () {
        expect(Schema::hasTable('agents'))->toBeTrue();

        expect(Schema::hasColumns('agents', [
            'id',
            'user_id',
            'name',
            'description',
            'config',
            'status',
            'ai_backend',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
    });

    test('agents table has foreign key to users', function () {
        $foreignKeys = DB::select("
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                ON tc.constraint_name = ccu.constraint_name
                AND tc.table_schema = ccu.table_schema
            WHERE tc.table_name = 'agents'
            AND tc.constraint_type = 'FOREIGN KEY'
            AND kcu.column_name = 'user_id'
            AND ccu.table_name = 'users'
        ");

        expect($foreignKeys)->not()->toBeEmpty();
    });

    test('conversations table has correct structure', function () {
        expect(Schema::hasTable('conversations'))->toBeTrue();

        expect(Schema::hasColumns('conversations', [
            'id',
            'agent_id',
            'user_id',
            'status',
            'messages',
            'metadata',
            'turn_count',
            'total_tokens',
            'started_at',
            'last_activity_at',
            'completed_at',
            'cli_session_id',
            'waiting_for',
            'pending_tool_request',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
    });

    test('conversations table has foreign keys', function () {
        $agentForeignKey = DB::select("
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                ON tc.constraint_name = ccu.constraint_name
                AND tc.table_schema = ccu.table_schema
            WHERE tc.table_name = 'conversations'
            AND tc.constraint_type = 'FOREIGN KEY'
            AND kcu.column_name = 'agent_id'
            AND ccu.table_name = 'agents'
        ");

        $userForeignKey = DB::select("
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                ON tc.constraint_name = ccu.constraint_name
                AND tc.table_schema = ccu.table_schema
            WHERE tc.table_name = 'conversations'
            AND tc.constraint_type = 'FOREIGN KEY'
            AND kcu.column_name = 'user_id'
            AND ccu.table_name = 'users'
        ");

        expect($agentForeignKey)->not()->toBeEmpty();
        expect($userForeignKey)->not()->toBeEmpty();
    });

    test('tools table has correct structure', function () {
        expect(Schema::hasTable('tools'))->toBeTrue();

        expect(Schema::hasColumns('tools', [
            'id',
            'user_id',
            'name',
            'type',
            'config',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
    });

    test('tools table has foreign key to users', function () {
        $foreignKeys = DB::select("
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                ON tc.constraint_name = ccu.constraint_name
                AND tc.table_schema = ccu.table_schema
            WHERE tc.table_name = 'tools'
            AND tc.constraint_type = 'FOREIGN KEY'
            AND kcu.column_name = 'user_id'
            AND ccu.table_name = 'users'
        ");

        expect($foreignKeys)->not()->toBeEmpty();
    });

    test('agent_tools pivot table has correct structure', function () {
        expect(Schema::hasTable('agent_tools'))->toBeTrue();

        expect(Schema::hasColumns('agent_tools', [
            'agent_id',
            'tool_id',
        ]))->toBeTrue();
    });

    test('agent_tools table has foreign keys', function () {
        $agentForeignKey = DB::select("
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                ON tc.constraint_name = ccu.constraint_name
                AND tc.table_schema = ccu.table_schema
            WHERE tc.table_name = 'agent_tools'
            AND tc.constraint_type = 'FOREIGN KEY'
            AND kcu.column_name = 'agent_id'
            AND ccu.table_name = 'agents'
        ");

        $toolForeignKey = DB::select("
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                ON tc.constraint_name = ccu.constraint_name
                AND tc.table_schema = ccu.table_schema
            WHERE tc.table_name = 'agent_tools'
            AND tc.constraint_type = 'FOREIGN KEY'
            AND kcu.column_name = 'tool_id'
            AND ccu.table_name = 'tools'
        ");

        expect($agentForeignKey)->not()->toBeEmpty();
        expect($toolForeignKey)->not()->toBeEmpty();
    });

    test('files table has correct structure', function () {
        expect(Schema::hasTable('files'))->toBeTrue();

        expect(Schema::hasColumns('files', [
            'id',
            'user_id',
            'path',
            'type',
            'size',
            'mime_type',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
    });

    test('files table has foreign key to users', function () {
        $foreignKeys = DB::select("
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
                ON tc.constraint_name = ccu.constraint_name
                AND tc.table_schema = ccu.table_schema
            WHERE tc.table_name = 'files'
            AND tc.constraint_type = 'FOREIGN KEY'
            AND kcu.column_name = 'user_id'
            AND ccu.table_name = 'users'
        ");

        expect($foreignKeys)->not()->toBeEmpty();
    });
});

describe('Vector Storage Schema', function () {
    test('pgvector extension is enabled', function () {
        $extensions = DB::select("SELECT extname FROM pg_extension WHERE extname = 'vector'");

        expect($extensions)->not->toBeEmpty()
            ->and($extensions[0]->extname)->toBe('vector');
    });

    test('document_chunks has embedding vector column', function () {
        $columns = DB::select("
            SELECT column_name, udt_name
            FROM information_schema.columns
            WHERE table_name = 'document_chunks'
            AND column_name = 'embedding'
        ");

        expect($columns)->not->toBeEmpty()
            ->and($columns[0]->udt_name)->toBe('vector');
    });

    test('document_chunks has HNSW index', function () {
        $indexes = DB::select("
            SELECT indexname, indexdef
            FROM pg_indexes
            WHERE tablename = 'document_chunks'
            AND indexname = 'idx_chunks_embedding_hnsw'
        ");

        expect($indexes)->not->toBeEmpty()
            ->and($indexes[0]->indexdef)->toContain('hnsw');
    });

    test('document_chunks has full-text search index', function () {
        $indexes = DB::select("
            SELECT indexname
            FROM pg_indexes
            WHERE tablename = 'document_chunks'
            AND indexname = 'idx_chunks_content_fts'
        ");

        expect($indexes)->not->toBeEmpty();
    });

    test('document_chunks has vector-related columns', function () {
        expect(Schema::hasColumns('document_chunks', [
            'embedding_raw',
            'embedding_model',
            'embedding_generated_at',
            'embedding_dimensions',
            'sparse_vector',
            'quality_score',
            'chunk_type',
            'content_hash',
        ]))->toBeTrue();
    });

    test('embedding_cache table exists with correct structure', function () {
        expect(Schema::hasTable('embedding_cache'))->toBeTrue();

        expect(Schema::hasColumns('embedding_cache', [
            'id',
            'content_hash',
            'embedding_raw',
            'embedding_model',
            'language',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
    });

    test('embedding_cache has unique constraint', function () {
        $constraints = DB::select("
            SELECT constraint_name
            FROM information_schema.table_constraints
            WHERE table_name = 'embedding_cache'
            AND constraint_type = 'UNIQUE'
        ");

        expect($constraints)->not->toBeEmpty();
    });

    test('retrieval_logs table exists with correct structure', function () {
        expect(Schema::hasTable('retrieval_logs'))->toBeTrue();

        expect(Schema::hasColumns('retrieval_logs', [
            'id',
            'conversation_id',
            'user_id',
            'query',
            'query_expansion',
            'retrieved_chunks',
            'retrieval_strategy',
            'retrieval_scores',
            'execution_time_ms',
            'chunks_found',
            'average_score',
            'user_found_helpful',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
    });

    test('retrieval_logs has foreign key to conversations', function () {
        $constraints = DB::select("
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            WHERE tc.table_name = 'retrieval_logs'
            AND tc.constraint_type = 'FOREIGN KEY'
            AND kcu.column_name = 'conversation_id'
        ");

        expect($constraints)->not->toBeEmpty();
    });

    test('retrieval_logs has foreign key to users', function () {
        $constraints = DB::select("
            SELECT tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            WHERE tc.table_name = 'retrieval_logs'
            AND tc.constraint_type = 'FOREIGN KEY'
            AND kcu.column_name = 'user_id'
        ");

        expect($constraints)->not->toBeEmpty();
    });
});
