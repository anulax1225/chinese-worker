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

    test('tasks table has correct structure', function () {
        expect(Schema::hasTable('tasks'))->toBeTrue();

        expect(Schema::hasColumns('tasks', [
            'id',
            'agent_id',
            'payload',
            'priority',
            'scheduled_at',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
    });

    test('tasks table has foreign key to agents', function () {
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'tasks'
            AND COLUMN_NAME = 'agent_id'
            AND REFERENCED_TABLE_NAME = 'agents'
        ");

        expect($foreignKeys)->not()->toBeEmpty();
    });

    test('executions table has correct structure', function () {
        expect(Schema::hasTable('executions'))->toBeTrue();

        expect(Schema::hasColumns('executions', [
            'id',
            'task_id',
            'status',
            'started_at',
            'completed_at',
            'result',
            'logs',
            'error',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
    });

    test('executions table has foreign key to tasks', function () {
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'executions'
            AND COLUMN_NAME = 'task_id'
            AND REFERENCED_TABLE_NAME = 'tasks'
        ");

        expect($foreignKeys)->not()->toBeEmpty();
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

    test('execution_files pivot table has correct structure', function () {
        expect(Schema::hasTable('execution_files'))->toBeTrue();

        expect(Schema::hasColumns('execution_files', [
            'execution_id',
            'file_id',
            'role',
        ]))->toBeTrue();
    });

    test('execution_files table has foreign keys', function () {
        $executionForeignKey = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'execution_files'
            AND COLUMN_NAME = 'execution_id'
            AND REFERENCED_TABLE_NAME = 'executions'
        ");

        $fileForeignKey = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'execution_files'
            AND COLUMN_NAME = 'file_id'
            AND REFERENCED_TABLE_NAME = 'files'
        ");

        expect($executionForeignKey)->not()->toBeEmpty();
        expect($fileForeignKey)->not()->toBeEmpty();
    });
});
