<?php

use App\DTOs\ToolCall;
use App\Services\Tools\ToolArgumentValidator;

beforeEach(function () {
    $this->validator = new ToolArgumentValidator;
});

describe('validate', function () {
    test('validates required fields are present', function () {
        $toolCall = new ToolCall(
            id: 'test-1',
            name: 'test_tool',
            arguments: []
        );

        $schema = [
            'name' => 'test_tool',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                ],
                'required' => ['query'],
            ],
        ];

        $result = $this->validator->validate($toolCall, $schema);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'])->toContain('Missing required argument: query');
    });

    test('validates required fields are not empty', function () {
        $toolCall = new ToolCall(
            id: 'test-1',
            name: 'test_tool',
            arguments: ['query' => '']
        );

        $schema = [
            'name' => 'test_tool',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                ],
                'required' => ['query'],
            ],
        ];

        $result = $this->validator->validate($toolCall, $schema);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'][0])->toContain('cannot be empty');
    });

    test('passes valid tool call with all required fields', function () {
        $toolCall = new ToolCall(
            id: 'test-1',
            name: 'test_tool',
            arguments: ['query' => 'search term']
        );

        $schema = [
            'name' => 'test_tool',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                ],
                'required' => ['query'],
            ],
        ];

        $result = $this->validator->validate($toolCall, $schema);

        expect($result['valid'])->toBeTrue();
        expect($result['errors'])->toBeEmpty();
    });

    test('validates enum values', function () {
        $toolCall = new ToolCall(
            id: 'test-1',
            name: 'test_tool',
            arguments: ['priority' => 'critical']
        );

        $schema = [
            'name' => 'test_tool',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'priority' => [
                        'type' => 'string',
                        'enum' => ['low', 'medium', 'high'],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate($toolCall, $schema);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'][0])->toContain('must be one of');
    });

    test('passes valid enum value', function () {
        $toolCall = new ToolCall(
            id: 'test-1',
            name: 'test_tool',
            arguments: ['priority' => 'high']
        );

        $schema = [
            'name' => 'test_tool',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'priority' => [
                        'type' => 'string',
                        'enum' => ['low', 'medium', 'high'],
                    ],
                ],
            ],
        ];

        $result = $this->validator->validate($toolCall, $schema);

        expect($result['valid'])->toBeTrue();
    });

    test('validates integer type', function () {
        $toolCall = new ToolCall(
            id: 'test-1',
            name: 'test_tool',
            arguments: ['count' => 'not a number']
        );

        $schema = [
            'name' => 'test_tool',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'count' => ['type' => 'integer'],
                ],
            ],
        ];

        $result = $this->validator->validate($toolCall, $schema);

        expect($result['valid'])->toBeFalse();
        expect($result['errors'][0])->toContain("expected type 'integer'");
    });

    test('accepts numeric string as integer', function () {
        $toolCall = new ToolCall(
            id: 'test-1',
            name: 'test_tool',
            arguments: ['count' => '42']
        );

        $schema = [
            'name' => 'test_tool',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'count' => ['type' => 'integer'],
                ],
            ],
        ];

        $result = $this->validator->validate($toolCall, $schema);

        expect($result['valid'])->toBeTrue();
    });

    test('ignores unknown arguments', function () {
        $toolCall = new ToolCall(
            id: 'test-1',
            name: 'test_tool',
            arguments: [
                'known' => 'value',
                'unknown' => 'extra field',
            ]
        );

        $schema = [
            'name' => 'test_tool',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'known' => ['type' => 'string'],
                ],
            ],
        ];

        $result = $this->validator->validate($toolCall, $schema);

        expect($result['valid'])->toBeTrue();
    });
});

describe('findSchema', function () {
    test('finds schema by tool name', function () {
        $schemas = [
            ['name' => 'tool_a', 'description' => 'First tool'],
            ['name' => 'tool_b', 'description' => 'Second tool'],
        ];

        $result = $this->validator->findSchema('tool_b', $schemas);

        expect($result)->not->toBeNull();
        expect($result['description'])->toBe('Second tool');
    });

    test('returns null for unknown tool', function () {
        $schemas = [
            ['name' => 'tool_a', 'description' => 'First tool'],
        ];

        $result = $this->validator->findSchema('unknown_tool', $schemas);

        expect($result)->toBeNull();
    });
});
