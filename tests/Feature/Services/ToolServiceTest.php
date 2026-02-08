<?php

use App\Models\Tool;
use App\Models\User;
use App\Services\ToolService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->toolService = new ToolService;
});

describe('API tool SSRF protection', function () {
    test('blocks localhost when SSRF protection is enabled', function () {
        config(['agent.api_tools.block_private_ips' => true]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'api',
            'config' => [
                'url' => 'http://localhost/admin',
                'method' => 'GET',
            ],
        ]);

        $result = $this->toolService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('SSRF protection');
    });

    test('blocks 127.0.0.1 when SSRF protection is enabled', function () {
        config(['agent.api_tools.block_private_ips' => true]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'api',
            'config' => [
                'url' => 'http://127.0.0.1/secret',
                'method' => 'GET',
            ],
        ]);

        $result = $this->toolService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('SSRF protection');
    });

    test('blocks private IP ranges when SSRF protection is enabled', function () {
        config(['agent.api_tools.block_private_ips' => true]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'api',
            'config' => [
                'url' => 'http://192.168.1.1/router',
                'method' => 'GET',
            ],
        ]);

        $result = $this->toolService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('SSRF protection');
    });

    test('blocks cloud metadata endpoint when SSRF protection is enabled', function () {
        config(['agent.api_tools.block_private_ips' => true]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'api',
            'config' => [
                'url' => 'http://169.254.169.254/latest/meta-data/',
                'method' => 'GET',
            ],
        ]);

        $result = $this->toolService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('SSRF protection');
    });

    test('blocks explicitly listed hosts', function () {
        config(['agent.api_tools.block_private_ips' => false]);
        config(['agent.api_tools.blocked_hosts' => ['internal.example.com']]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'api',
            'config' => [
                'url' => 'http://internal.example.com/api',
                'method' => 'GET',
            ],
        ]);

        $result = $this->toolService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('Blocked host');
    });

    test('allows localhost when SSRF protection is disabled', function () {
        config(['agent.api_tools.block_private_ips' => false]);
        config(['agent.api_tools.blocked_hosts' => []]);

        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'api',
            'config' => [
                'url' => 'http://localhost/test',
                'method' => 'GET',
            ],
        ]);

        $result = $this->toolService->execute($tool, []);

        // Should NOT be blocked by SSRF - may fail for other reasons
        expect($result->error)->not->toContain('SSRF protection');
        expect($result->error)->not->toContain('Blocked host');
    });

    test('returns error for missing URL configuration', function () {
        $tool = Tool::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'api',
            'config' => [
                'method' => 'GET',
            ],
        ]);

        $result = $this->toolService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('missing URL');
    });
});

describe('function tool', function () {
    test('returns not supported error', function () {
        $tool = Tool::factory()->function()->create([
            'user_id' => $this->user->id,
        ]);

        $result = $this->toolService->execute($tool, []);

        expect($result->success)->toBeFalse();
        expect($result->error)->toContain('not yet supported');
    });
});
