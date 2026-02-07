<?php

use App\Services\Prompts\ContextMerger;

beforeEach(function () {
    $this->merger = new ContextMerger;
});

test('merges single context array', function () {
    $context = ['name' => 'Claude', 'role' => 'assistant'];

    $result = $this->merger->merge($context);

    expect($result)->toBe(['name' => 'Claude', 'role' => 'assistant']);
});

test('merges multiple context arrays with later taking precedence', function () {
    $first = ['name' => 'Default', 'color' => 'blue'];
    $second = ['name' => 'Claude'];

    $result = $this->merger->merge($first, $second);

    expect($result)->toBe(['name' => 'Claude', 'color' => 'blue']);
});

test('filters out null values during merge', function () {
    $first = ['name' => 'Default', 'age' => 10];
    $second = ['name' => null, 'role' => 'assistant'];

    $result = $this->merger->merge($first, $second);

    expect($result)->toBe(['name' => 'Default', 'age' => 10, 'role' => 'assistant']);
});

test('handles empty arrays', function () {
    $first = ['name' => 'Claude'];
    $second = [];

    $result = $this->merger->merge($first, $second);

    expect($result)->toBe(['name' => 'Claude']);
});

test('merges three or more context arrays', function () {
    $system = ['date' => '2024-01-15', 'app' => 'MyApp'];
    $agent = ['name' => 'Agent1', 'app' => 'AgentApp'];
    $prompt = ['greeting' => 'Hello'];
    $runtime = ['name' => 'FinalName'];

    $result = $this->merger->merge($system, $agent, $prompt, $runtime);

    expect($result)->toBe([
        'date' => '2024-01-15',
        'app' => 'AgentApp',
        'name' => 'FinalName',
        'greeting' => 'Hello',
    ]);
});

test('returns empty array when no contexts provided', function () {
    $result = $this->merger->merge();

    expect($result)->toBe([]);
});

test('preserves zero and empty string values', function () {
    $first = ['count' => 0, 'name' => ''];
    $second = ['active' => false];

    $result = $this->merger->merge($first, $second);

    expect($result)->toBe(['count' => 0, 'name' => '', 'active' => false]);
});
