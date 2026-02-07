<?php

use App\Services\Prompts\BladePromptRenderer;

beforeEach(function () {
    $this->renderer = app(BladePromptRenderer::class);
});

test('renders simple template without variables', function () {
    $template = 'Hello, World!';

    $result = $this->renderer->render($template);

    expect($result)->toBe('Hello, World!');
});

test('renders template with blade echo syntax', function () {
    $template = 'Hello, {{ $name }}!';

    $result = $this->renderer->render($template, ['name' => 'Claude']);

    expect($result)->toBe('Hello, Claude!');
});

test('renders template with multiple variables', function () {
    $template = 'Today is {{ $date }} and the time is {{ $time }}.';

    $result = $this->renderer->render($template, [
        'date' => '2024-01-15',
        'time' => '14:30',
    ]);

    expect($result)->toBe('Today is 2024-01-15 and the time is 14:30.');
});

test('renders template with blade conditionals', function () {
    $template = '@if($show_greeting)Hello!@endif';

    $resultWithTrue = $this->renderer->render($template, ['show_greeting' => true]);
    $resultWithFalse = $this->renderer->render($template, ['show_greeting' => false]);

    expect($resultWithTrue)->toBe('Hello!');
    expect($resultWithFalse)->toBe('');
});

test('renders template with blade foreach', function () {
    $template = '@foreach($items as $item)- {{ $item }}@endforeach';

    $result = $this->renderer->render($template, [
        'items' => ['apple', 'banana', 'cherry'],
    ]);

    expect($result)->toContain('- apple');
    expect($result)->toContain('- banana');
    expect($result)->toContain('- cherry');
});

test('handles missing variables with null coalescing', function () {
    $template = 'Hello, {{ $name ?? "Guest" }}!';

    $result = $this->renderer->render($template, []);

    expect($result)->toBe('Hello, Guest!');
});

test('renders empty string for empty template', function () {
    $result = $this->renderer->render('', []);

    expect($result)->toBe('');
});
