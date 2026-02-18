<?php

use App\DTOs\NormalizedModelConfig;
use App\Jobs\ProcessConversationTurn;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AI\FakeBackend;
use App\Services\AIBackendManager;
use App\Services\ConversationService;
use Illuminate\Support\Facades\Config;

describe('ProcessConversationTurn context filtering', function () {
    beforeEach(function () {
        Config::set('ai.backends.fake', [
            'driver' => 'fake',
            'model' => 'test-model',
        ]);
        Config::set('ai.default', 'fake');
    });

    /**
     * Create a conversation with a fake-backend agent and one user message.
     */
    $makeConversation = function (): Conversation {
        $user = User::factory()->create();
        $agent = Agent::factory()->create([
            'ai_backend' => 'fake',
            'status' => 'active',
        ]);

        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create([
                'status' => 'active',
                'context_limit' => 128000,
                'total_tokens' => 100,
            ]);

        $conversation->addMessage(['role' => 'user', 'content' => 'Hello']);

        return $conversation;
    };

    /**
     * Build a mocked AIBackendManager that returns FakeBackend for forAgent().
     */
    $makeBackendManager = function (): AIBackendManager {
        $fakeBackend = new FakeBackend(['model' => 'test-model']);

        $modelConfig = new NormalizedModelConfig(
            model: 'test-model',
            temperature: 0.7,
            maxTokens: 4096,
            contextLength: 4096,
            timeout: 120,
        );

        $mockManager = Mockery::mock(AIBackendManager::class);
        $mockManager->shouldReceive('forAgent')
            ->once()
            ->andReturn([
                'backend' => $fakeBackend->withConfig($modelConfig),
                'config' => $modelConfig,
            ]);

        return $mockManager;
    };

    test('ProcessConversationTurn uses ConversationService for messages', function () use ($makeConversation, $makeBackendManager) {
        $conversation = $makeConversation();

        $mockConversationService = Mockery::mock(ConversationService::class);
        $mockConversationService->shouldReceive('getMessagesForAI')
            ->once()
            ->withArgs(function (Conversation $conv) use ($conversation): bool {
                return $conv->id === $conversation->id;
            })
            ->andReturn($conversation->getMessages());

        $job = new ProcessConversationTurn($conversation);
        $job->handle(
            $makeBackendManager(),
            $mockConversationService,
        );

        // Mockery verifies the expectation (called once) on teardown
    });

    test('context filtering passes toolDefinitionTokens greater than zero', function () use ($makeConversation, $makeBackendManager) {
        $conversation = $makeConversation();

        $capturedTokens = null;

        $mockConversationService = Mockery::mock(ConversationService::class);
        $mockConversationService->shouldReceive('getMessagesForAI')
            ->once()
            ->withArgs(function (
                Conversation $conv,
                bool $forceFilter,
                bool $skipFilter,
                int $maxOutputTokens,
                int $toolDefinitionTokens
            ) use ($conversation, &$capturedTokens): bool {
                $capturedTokens = $toolDefinitionTokens;

                return $conv->id === $conversation->id;
            })
            ->andReturn($conversation->getMessages());

        $job = new ProcessConversationTurn($conversation);
        $job->handle(
            $makeBackendManager(),
            $mockConversationService,
        );

        expect($capturedTokens)->toBeGreaterThan(0);
    });
});
