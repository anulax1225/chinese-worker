<?php

use App\DTOs\ChatMessage;

test('creates system message', function () {
    $message = ChatMessage::system('System message');

    expect($message->role)->toBe('system')
        ->and($message->content)->toBe('System message')
        ->and($message->toolCalls)->toBeNull()
        ->and($message->toolCallId)->toBeNull()
        ->and($message->name)->toBeNull();
});

test('creates user message', function () {
    $message = ChatMessage::user('User message');

    expect($message->role)->toBe('user')
        ->and($message->content)->toBe('User message')
        ->and($message->toolCalls)->toBeNull()
        ->and($message->toolCallId)->toBeNull()
        ->and($message->name)->toBeNull();
});

test('creates user message with images', function () {
    $images = ['base64_image_data'];
    $message = ChatMessage::user('User message', $images);

    expect($message->role)->toBe('user')
        ->and($message->content)->toBe('User message')
        ->and($message->images)->toBe($images)
        ->and($message->name)->toBeNull();
});

test('creates assistant message', function () {
    $message = ChatMessage::assistant('Assistant response');

    expect($message->role)->toBe('assistant')
        ->and($message->content)->toBe('Assistant response')
        ->and($message->toolCalls)->toBeNull()
        ->and($message->toolCallId)->toBeNull()
        ->and($message->name)->toBeNull();
});

test('creates assistant message with tool calls', function () {
    $toolCalls = [
        [
            'id' => 'call_123',
            'type' => 'function',
            'function' => [
                'name' => 'web_search',
                'arguments' => json_encode(['query' => 'test']),
            ],
        ],
    ];
    $message = ChatMessage::assistant('', $toolCalls);

    expect($message->role)->toBe('assistant')
        ->and($message->toolCalls)->toBe($toolCalls)
        ->and($message->name)->toBeNull();
});

test('creates assistant message with thinking', function () {
    $message = ChatMessage::assistant('Response', null, 'Thinking content');

    expect($message->role)->toBe('assistant')
        ->and($message->content)->toBe('Response')
        ->and($message->thinking)->toBe('Thinking content')
        ->and($message->name)->toBeNull();
});

test('creates tool message with name', function () {
    $message = ChatMessage::tool('Tool result', 'call_123', 'web_search');

    expect($message->role)->toBe('tool')
        ->and($message->content)->toBe('Tool result')
        ->and($message->toolCallId)->toBe('call_123')
        ->and($message->name)->toBe('web_search');
});

test('creates tool message without name', function () {
    $message = ChatMessage::tool('Tool result', 'call_123');

    expect($message->role)->toBe('tool')
        ->and($message->content)->toBe('Tool result')
        ->and($message->toolCallId)->toBe('call_123')
        ->and($message->name)->toBeNull();
});

test('converts tool message to array includes name', function () {
    $message = ChatMessage::tool('Tool result', 'call_123', 'web_search');
    $array = $message->toArray();

    expect($array)->toHaveKey('role', 'tool')
        ->toHaveKey('content', 'Tool result')
        ->toHaveKey('tool_call_id', 'call_123')
        ->toHaveKey('name', 'web_search');
});

test('converts message to array includes all fields', function () {
    $message = ChatMessage::assistant('Response', null, 'Thinking');
    $array = $message->toArray();

    expect($array)->toHaveKeys(['role', 'content', 'tool_calls', 'tool_call_id', 'images', 'thinking', 'name'])
        ->and($array['role'])->toBe('assistant')
        ->and($array['content'])->toBe('Response')
        ->and($array['thinking'])->toBe('Thinking')
        ->and($array['name'])->toBeNull();
});

test('creates message from array with name', function () {
    $data = [
        'role' => 'tool',
        'content' => 'Tool result',
        'tool_call_id' => 'call_123',
        'name' => 'web_search',
    ];
    $message = ChatMessage::fromArray($data);

    expect($message->role)->toBe('tool')
        ->and($message->content)->toBe('Tool result')
        ->and($message->toolCallId)->toBe('call_123')
        ->and($message->name)->toBe('web_search');
});

test('creates message from array without name', function () {
    $data = [
        'role' => 'tool',
        'content' => 'Tool result',
        'tool_call_id' => 'call_123',
    ];
    $message = ChatMessage::fromArray($data);

    expect($message->role)->toBe('tool')
        ->and($message->content)->toBe('Tool result')
        ->and($message->toolCallId)->toBe('call_123')
        ->and($message->name)->toBeNull();
});

test('round trip serialization preserves name', function () {
    $original = ChatMessage::tool('Result', 'call_456', 'bash');
    $array = $original->toArray();
    $restored = ChatMessage::fromArray($array);

    expect($restored->role)->toBe($original->role)
        ->and($restored->content)->toBe($original->content)
        ->and($restored->toolCallId)->toBe($original->toolCallId)
        ->and($restored->name)->toBe($original->name)
        ->and($restored->name)->toBe('bash');
});
