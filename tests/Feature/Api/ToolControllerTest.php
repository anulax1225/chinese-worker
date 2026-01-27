<?php

use App\Models\Tool;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('ToolController', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    describe('index', function () {
        test('returns builtin tools by default', function () {
            $response = $this->getJson('/api/v1/tools');

            $response->assertOk();
            $data = $response->json('data');

            // Check that builtin tools are included (names are lowercase)
            $builtinNames = collect($data)->pluck('name')->toArray();
            expect($builtinNames)->toContain('read')
                ->and($builtinNames)->toContain('write')
                ->and($builtinNames)->toContain('edit')
                ->and($builtinNames)->toContain('glob')
                ->and($builtinNames)->toContain('grep')
                ->and($builtinNames)->toContain('bash');
        });

        test('returns user tools alongside builtin tools', function () {
            $tool = Tool::factory()->create(['user_id' => $this->user->id, 'name' => 'My Custom Tool']);

            $response = $this->getJson('/api/v1/tools');

            $response->assertOk();
            $names = collect($response->json('data'))->pluck('name')->toArray();

            // Should have both builtin and custom tools
            expect($names)->toContain('read')
                ->and($names)->toContain('My Custom Tool');
        });

        test('can exclude builtin tools', function () {
            $tool = Tool::factory()->create(['user_id' => $this->user->id, 'name' => 'My Custom Tool']);

            $response = $this->getJson('/api/v1/tools?include_builtin=false');

            $response->assertOk();
            $names = collect($response->json('data'))->pluck('name')->toArray();

            // Should only have custom tools
            expect($names)->not->toContain('read')
                ->and($names)->toContain('My Custom Tool');
        });

        test('can filter by type builtin', function () {
            Tool::factory()->create(['user_id' => $this->user->id, 'type' => 'api']);

            $response = $this->getJson('/api/v1/tools?type=builtin');

            $response->assertOk();
            $types = collect($response->json('data'))->pluck('type')->unique()->toArray();

            expect($types)->toBe(['builtin']);
        });

        test('builtin tools have proper schema', function () {
            $response = $this->getJson('/api/v1/tools?type=builtin');

            $response->assertOk();
            $readTool = collect($response->json('data'))->firstWhere('name', 'read');

            expect($readTool)->not->toBeNull()
                ->and($readTool['type'])->toBe('builtin')
                ->and($readTool['description'])->toBeString()
                ->and($readTool['parameters'])->toBeArray()
                ->and($readTool['parameters']['type'])->toBe('object')
                ->and($readTool['parameters']['properties'])->toHaveKey('file_path');
        });

        test('returns paginated results', function () {
            $response = $this->getJson('/api/v1/tools?per_page=5');

            $response->assertOk()
                ->assertJsonStructure([
                    'data',
                    'meta' => ['current_page', 'per_page', 'total', 'last_page'],
                ]);
        });
    });
});
