<?php

use App\Models\Agent;
use App\Models\SystemPrompt;
use App\Services\Prompts\PromptAssembler;

beforeEach(function () {
    $this->assembler = app(PromptAssembler::class);
});

test('returns empty string when no system prompts exist', function () {
    $agent = Agent::factory()->create();

    $result = $this->assembler->assemble($agent);

    expect($result)->toBe('');
});

test('assembles prompt from single system prompt', function () {
    $agent = Agent::factory()->create();
    $prompt = SystemPrompt::factory()->create([
        'template' => 'Hello, I am {{ $agent_name }}.',
        'is_active' => true,
    ]);

    $agent->systemPrompts()->attach($prompt->id, ['order' => 0]);

    $result = $this->assembler->assemble($agent);

    expect($result)->toContain('Hello, I am '.$agent->name);
});

test('assembles prompts in correct order', function () {
    $agent = Agent::factory()->create();

    $first = SystemPrompt::factory()->create([
        'template' => 'FIRST',
        'is_active' => true,
    ]);
    $second = SystemPrompt::factory()->create([
        'template' => 'SECOND',
        'is_active' => true,
    ]);

    $agent->systemPrompts()->attach($first->id, ['order' => 1]);
    $agent->systemPrompts()->attach($second->id, ['order' => 0]);

    $result = $this->assembler->assemble($agent);

    // SECOND should come first because it has order 0
    expect(strpos($result, 'SECOND'))->toBeLessThan(strpos($result, 'FIRST'));
});

test('skips inactive prompts', function () {
    $agent = Agent::factory()->create();

    $active = SystemPrompt::factory()->create([
        'template' => 'ACTIVE',
        'is_active' => true,
    ]);
    $inactive = SystemPrompt::factory()->create([
        'template' => 'INACTIVE',
        'is_active' => false,
    ]);

    $agent->systemPrompts()->attach($active->id, ['order' => 0]);
    $agent->systemPrompts()->attach($inactive->id, ['order' => 1]);

    $result = $this->assembler->assemble($agent);

    expect($result)->toContain('ACTIVE');
    expect($result)->not->toContain('INACTIVE');
});

test('renders template with system context variables', function () {
    $agent = Agent::factory()->create();
    $prompt = SystemPrompt::factory()->create([
        'template' => 'Today is {{ $date }}.',
        'is_active' => true,
    ]);

    $agent->systemPrompts()->attach($prompt->id, ['order' => 0]);

    $result = $this->assembler->assemble($agent);

    expect($result)->toContain('Today is '.now()->toDateString());
});

test('applies agent context variables', function () {
    $agent = Agent::factory()->create([
        'context_variables' => ['custom_var' => 'custom_value'],
    ]);
    $prompt = SystemPrompt::factory()->create([
        'template' => 'Custom: {{ $custom_var }}',
        'is_active' => true,
    ]);

    $agent->systemPrompts()->attach($prompt->id, ['order' => 0]);

    $result = $this->assembler->assemble($agent);

    expect($result)->toContain('Custom: custom_value');
});

test('applies prompt default values', function () {
    $agent = Agent::factory()->create();
    $prompt = SystemPrompt::factory()->create([
        'template' => 'Mode: {{ $mode }}',
        'default_values' => ['mode' => 'default_mode'],
        'is_active' => true,
    ]);

    $agent->systemPrompts()->attach($prompt->id, ['order' => 0]);

    $result = $this->assembler->assemble($agent);

    expect($result)->toContain('Mode: default_mode');
});

test('pivot variable overrides take precedence', function () {
    $agent = Agent::factory()->create();
    $prompt = SystemPrompt::factory()->create([
        'template' => 'Mode: {{ $mode }}',
        'default_values' => ['mode' => 'default_mode'],
        'is_active' => true,
    ]);

    $agent->systemPrompts()->attach($prompt->id, [
        'order' => 0,
        'variable_overrides' => ['mode' => 'overridden_mode'],
    ]);

    $result = $this->assembler->assemble($agent);

    expect($result)->toContain('Mode: overridden_mode');
});

test('stores last context for debugging', function () {
    $agent = Agent::factory()->create([
        'context_variables' => ['test' => 'value'],
    ]);
    $prompt = SystemPrompt::factory()->create([
        'template' => 'Test',
        'is_active' => true,
    ]);

    $agent->systemPrompts()->attach($prompt->id, ['order' => 0]);

    $this->assembler->assemble($agent);
    $context = $this->assembler->getLastContext();

    expect($context)->toHaveKey('date');
    expect($context)->toHaveKey('agent_name');
    expect($context['test'])->toBe('value');
});
