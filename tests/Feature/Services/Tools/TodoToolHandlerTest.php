<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Todo;
use App\Models\User;
use App\Services\Tools\TodoToolHandler;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->create(['user_id' => $this->user->id]);
    $this->conversation = Conversation::factory()->create([
        'user_id' => $this->user->id,
        'agent_id' => $this->agent->id,
    ]);
    $this->handler = new TodoToolHandler($this->conversation);
});

describe('todo_add', function () {
    test('creates a new todo with default priority', function () {
        $result = $this->handler->execute('todo_add', ['item' => 'Test task']);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('Test task');
        expect($result->output)->toContain('medium');

        $this->assertDatabaseHas('todos', [
            'conversation_id' => $this->conversation->id,
            'agent_id' => $this->agent->id,
            'content' => 'Test task',
            'priority' => 'medium',
        ]);
    });

    test('creates a todo with specified priority', function () {
        $result = $this->handler->execute('todo_add', [
            'item' => 'High priority task',
            'priority' => 'high',
        ]);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('high');

        $this->assertDatabaseHas('todos', [
            'conversation_id' => $this->conversation->id,
            'content' => 'High priority task',
            'priority' => 'high',
        ]);
    });
});

describe('todo_list', function () {
    test('returns empty message when no todos exist', function () {
        $result = $this->handler->execute('todo_list', []);

        expect($result->success)->toBeTrue();
        expect($result->output)->toBe('No todos found.');
    });

    test('lists todos for the conversation', function () {
        Todo::factory()->create([
            'agent_id' => $this->agent->id,
            'conversation_id' => $this->conversation->id,
            'content' => 'First task',
            'priority' => 'high',
        ]);

        Todo::factory()->create([
            'agent_id' => $this->agent->id,
            'conversation_id' => $this->conversation->id,
            'content' => 'Second task',
            'priority' => 'low',
        ]);

        $result = $this->handler->execute('todo_list', []);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('First task');
        expect($result->output)->toContain('Second task');
    });

    test('does not list todos from other conversations', function () {
        $otherConversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $this->agent->id,
        ]);

        Todo::factory()->create([
            'agent_id' => $this->agent->id,
            'conversation_id' => $otherConversation->id,
            'content' => 'Other conversation task',
        ]);

        $result = $this->handler->execute('todo_list', []);

        expect($result->success)->toBeTrue();
        expect($result->output)->toBe('No todos found.');
    });
});

describe('todo_complete', function () {
    test('marks a todo as completed', function () {
        $todo = Todo::factory()->create([
            'agent_id' => $this->agent->id,
            'conversation_id' => $this->conversation->id,
            'content' => 'Complete me',
            'status' => 'pending',
        ]);

        $result = $this->handler->execute('todo_complete', ['id' => $todo->id]);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('complete');

        $todo->refresh();
        expect($todo->status)->toBe('completed');
    });

    test('returns error for non-existent todo', function () {
        $result = $this->handler->execute('todo_complete', ['id' => 99999]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('not found');
    });

    test('cannot complete todo from another conversation', function () {
        $otherConversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $this->agent->id,
        ]);

        $todo = Todo::factory()->create([
            'agent_id' => $this->agent->id,
            'conversation_id' => $otherConversation->id,
        ]);

        $result = $this->handler->execute('todo_complete', ['id' => $todo->id]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('not found');
    });
});

describe('todo_update', function () {
    test('updates todo content', function () {
        $todo = Todo::factory()->create([
            'agent_id' => $this->agent->id,
            'conversation_id' => $this->conversation->id,
            'content' => 'Original content',
        ]);

        $result = $this->handler->execute('todo_update', [
            'id' => $todo->id,
            'item' => 'Updated content',
        ]);

        expect($result->success)->toBeTrue();

        $todo->refresh();
        expect($todo->content)->toBe('Updated content');
    });

    test('returns error for non-existent todo', function () {
        $result = $this->handler->execute('todo_update', [
            'id' => 99999,
            'item' => 'New content',
        ]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('not found');
    });
});

describe('todo_delete', function () {
    test('deletes a todo', function () {
        $todo = Todo::factory()->create([
            'agent_id' => $this->agent->id,
            'conversation_id' => $this->conversation->id,
        ]);

        $result = $this->handler->execute('todo_delete', ['id' => $todo->id]);

        expect($result->success)->toBeTrue();
        $this->assertDatabaseMissing('todos', ['id' => $todo->id]);
    });

    test('returns error for non-existent todo', function () {
        $result = $this->handler->execute('todo_delete', ['id' => 99999]);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('not found');
    });

    test('cannot delete todo from another conversation', function () {
        $otherConversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $this->agent->id,
        ]);

        $todo = Todo::factory()->create([
            'agent_id' => $this->agent->id,
            'conversation_id' => $otherConversation->id,
        ]);

        $result = $this->handler->execute('todo_delete', ['id' => $todo->id]);

        expect($result->success)->toBeFalse();
        $this->assertDatabaseHas('todos', ['id' => $todo->id]);
    });
});

describe('todo_clear', function () {
    test('clears all todos for the conversation', function () {
        Todo::factory()->count(3)->create([
            'agent_id' => $this->agent->id,
            'conversation_id' => $this->conversation->id,
        ]);

        $result = $this->handler->execute('todo_clear', []);

        expect($result->success)->toBeTrue();
        expect($result->output)->toContain('Cleared 3 todos');

        $this->assertDatabaseMissing('todos', [
            'conversation_id' => $this->conversation->id,
        ]);
    });

    test('does not clear todos from other conversations', function () {
        $otherConversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $this->agent->id,
        ]);

        $otherTodo = Todo::factory()->create([
            'agent_id' => $this->agent->id,
            'conversation_id' => $otherConversation->id,
        ]);

        Todo::factory()->create([
            'agent_id' => $this->agent->id,
            'conversation_id' => $this->conversation->id,
        ]);

        $this->handler->execute('todo_clear', []);

        $this->assertDatabaseHas('todos', ['id' => $otherTodo->id]);
    });
});

describe('unknown tool', function () {
    test('returns error for unknown tool name', function () {
        $result = $this->handler->execute('todo_unknown', []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('Unknown todo tool');
    });
});
