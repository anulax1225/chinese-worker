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
            'code',
            'config',
            'status',
            'ai_backend',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
    });

    test('agents table has foreign key to users', function () {
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'agents'
            AND COLUMN_NAME = 'user_id'
            AND REFERENCED_TABLE_NAME = 'users'
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
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'conversations'
            AND COLUMN_NAME = 'agent_id'
            AND REFERENCED_TABLE_NAME = 'agents'
        ");

        $userForeignKey = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'conversations'
            AND COLUMN_NAME = 'user_id'
            AND REFERENCED_TABLE_NAME = 'users'
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
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'tools'
            AND COLUMN_NAME = 'user_id'
            AND REFERENCED_TABLE_NAME = 'users'
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
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'agent_tools'
            AND COLUMN_NAME = 'agent_id'
            AND REFERENCED_TABLE_NAME = 'agents'
        ");

        $toolForeignKey = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'agent_tools'
            AND COLUMN_NAME = 'tool_id'
            AND REFERENCED_TABLE_NAME = 'tools'
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
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'files'
            AND COLUMN_NAME = 'user_id'
            AND REFERENCED_TABLE_NAME = 'users'
        ");

        expect($foreignKeys)->not()->toBeEmpty();
    });
});
