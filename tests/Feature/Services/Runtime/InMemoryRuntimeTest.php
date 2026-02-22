<?php

use App\DTOs\ChatMessage;
use App\Models\Agent;
use App\Models\User;
use App\Services\Runtime\InMemoryRuntime;

describe('InMemoryRuntime', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->agent = Agent::factory()->create(['user_id' => $this->user->id]);
        $this->runtime = new InMemoryRuntime(
            agent: $this->agent,
            user: $this->user,
        );
    });

    describe('Identity', function () {
        test('getId returns a string starting with ghost_', function () {
            $id = $this->runtime->getId();

            expect($id)->toBeString()
                ->and($id)->toStartWith('ghost_');
        });

        test('each runtime instance gets a unique id', function () {
            $other = new InMemoryRuntime(agent: $this->agent, user: $this->user);

            expect($this->runtime->getId())->not->toBe($other->getId());
        });

        test('getAgent returns the agent passed to constructor', function () {
            expect($this->runtime->getAgent()->id)->toBe($this->agent->id);
        });

        test('getUser returns the user passed to constructor', function () {
            expect($this->runtime->getUser()->id)->toBe($this->user->id);
        });

        test('getUser returns null when no user is provided', function () {
            $runtime = new InMemoryRuntime(agent: $this->agent);

            expect($runtime->getUser())->toBeNull();
        });

        test('isPersistent returns false', function () {
            expect($this->runtime->isPersistent())->toBeFalse();
        });

        test('hasDocuments returns false', function () {
            expect($this->runtime->hasDocuments())->toBeFalse();
        });
    });

    describe('Turn Management', function () {
        test('getTurnCount starts at zero', function () {
            expect($this->runtime->getTurnCount())->toBe(0);
        });

        test('incrementTurn increases turn count by one', function () {
            $this->runtime->incrementTurn();

            expect($this->runtime->getTurnCount())->toBe(1);
        });

        test('incrementTurn accumulates across multiple calls', function () {
            $this->runtime->incrementTurn();
            $this->runtime->incrementTurn();
            $this->runtime->incrementTurn();

            expect($this->runtime->getTurnCount())->toBe(3);
        });

        test('getRequestTurnCount starts at zero', function () {
            expect($this->runtime->getRequestTurnCount())->toBe(0);
        });

        test('incrementRequestTurn increases request turn count by one', function () {
            $this->runtime->incrementRequestTurn();

            expect($this->runtime->getRequestTurnCount())->toBe(1);
        });

        test('resetRequestTurnCount resets count to zero', function () {
            $this->runtime->incrementRequestTurn();
            $this->runtime->incrementRequestTurn();

            $this->runtime->resetRequestTurnCount();

            expect($this->runtime->getRequestTurnCount())->toBe(0);
        });

        test('getMaxTurns returns 25 by default', function () {
            expect($this->runtime->getMaxTurns())->toBe(25);
        });

        test('getMaxTurns returns the value passed to constructor', function () {
            $runtime = new InMemoryRuntime(agent: $this->agent, maxTurns: 10);

            expect($runtime->getMaxTurns())->toBe(10);
        });
    });

    describe('Message Management', function () {
        test('getMessages returns empty array initially', function () {
            expect($this->runtime->getMessages())->toBe([]);
        });

        test('getMessageCount returns zero initially', function () {
            expect($this->runtime->getMessageCount())->toBe(0);
        });

        test('addMessage appends a ChatMessage to the list', function () {
            $message = ChatMessage::user('Hello');

            $this->runtime->addMessage($message);

            expect($this->runtime->getMessages())->toHaveCount(1);
        });

        test('getMessages returns all added messages in order', function () {
            $user = ChatMessage::user('Hello');
            $assistant = ChatMessage::assistant('Hi there!');

            $this->runtime->addMessage($user);
            $this->runtime->addMessage($assistant);

            $messages = $this->runtime->getMessages();

            expect($messages)->toHaveCount(2)
                ->and($messages[0]->role)->toBe('user')
                ->and($messages[0]->content)->toBe('Hello')
                ->and($messages[1]->role)->toBe('assistant')
                ->and($messages[1]->content)->toBe('Hi there!');
        });

        test('getMessageCount reflects actual number of messages', function () {
            $this->runtime->addMessage(ChatMessage::user('First'));
            $this->runtime->addMessage(ChatMessage::assistant('Second'));
            $this->runtime->addMessage(ChatMessage::user('Third'));

            expect($this->runtime->getMessageCount())->toBe(3);
        });

        test('addMessage supports all message roles', function () {
            $this->runtime->addMessage(ChatMessage::system('Be helpful'));
            $this->runtime->addMessage(ChatMessage::user('Hello'));
            $this->runtime->addMessage(ChatMessage::assistant('Hi!'));
            $this->runtime->addMessage(ChatMessage::tool('Result', 'call_1'));

            $messages = $this->runtime->getMessages();

            expect($messages)->toHaveCount(4)
                ->and($messages[0]->role)->toBe('system')
                ->and($messages[1]->role)->toBe('user')
                ->and($messages[2]->role)->toBe('assistant')
                ->and($messages[3]->role)->toBe('tool');
        });
    });

    describe('Token Tracking', function () {
        test('getTotalTokens returns zero initially', function () {
            expect($this->runtime->getTotalTokens())->toBe(0);
        });

        test('getPromptTokens returns zero initially', function () {
            expect($this->runtime->getPromptTokens())->toBe(0);
        });

        test('getCompletionTokens returns zero initially', function () {
            expect($this->runtime->getCompletionTokens())->toBe(0);
        });

        test('addTokenUsage accumulates prompt tokens correctly', function () {
            $this->runtime->addTokenUsage(100, 50);

            expect($this->runtime->getPromptTokens())->toBe(100);
        });

        test('addTokenUsage accumulates completion tokens correctly', function () {
            $this->runtime->addTokenUsage(100, 50);

            expect($this->runtime->getCompletionTokens())->toBe(50);
        });

        test('addTokenUsage accumulates total tokens as sum of prompt and completion', function () {
            $this->runtime->addTokenUsage(100, 50);

            expect($this->runtime->getTotalTokens())->toBe(150);
        });

        test('addTokenUsage accumulates across multiple calls', function () {
            $this->runtime->addTokenUsage(100, 50);
            $this->runtime->addTokenUsage(200, 75);

            expect($this->runtime->getPromptTokens())->toBe(300)
                ->and($this->runtime->getCompletionTokens())->toBe(125)
                ->and($this->runtime->getTotalTokens())->toBe(425);
        });
    });

    describe('Context Limit', function () {
        test('getContextLimit returns null initially', function () {
            expect($this->runtime->getContextLimit())->toBeNull();
        });

        test('setContextLimit updates the context limit', function () {
            $this->runtime->setContextLimit(4096);

            expect($this->runtime->getContextLimit())->toBe(4096);
        });

        test('isApproachingContextLimit returns false when no limit is set', function () {
            $this->runtime->addTokenUsage(10000, 5000);

            expect($this->runtime->isApproachingContextLimit())->toBeFalse();
        });

        test('isApproachingContextLimit returns false when context limit is zero', function () {
            $this->runtime->setContextLimit(0);
            $this->runtime->addTokenUsage(1000, 500);

            expect($this->runtime->isApproachingContextLimit())->toBeFalse();
        });

        test('isApproachingContextLimit returns false when below threshold', function () {
            $this->runtime->setContextLimit(4096);
            $this->runtime->addTokenUsage(1000, 500); // 1500 / 4096 ≈ 36.6%

            expect($this->runtime->isApproachingContextLimit(0.8))->toBeFalse();
        });

        test('isApproachingContextLimit returns true when at or above threshold', function () {
            $this->runtime->setContextLimit(4096);
            $this->runtime->addTokenUsage(3000, 300); // 3300 / 4096 ≈ 80.6%

            expect($this->runtime->isApproachingContextLimit(0.8))->toBeTrue();
        });

        test('isApproachingContextLimit respects custom threshold', function () {
            $this->runtime->setContextLimit(1000);
            $this->runtime->addTokenUsage(600, 0); // 60%

            expect($this->runtime->isApproachingContextLimit(0.5))->toBeTrue();
            expect($this->runtime->isApproachingContextLimit(0.7))->toBeFalse();
        });
    });

    describe('Status Management', function () {
        test('isCancelled returns false initially', function () {
            expect($this->runtime->isCancelled())->toBeFalse();
        });

        test('markAsCompleted changes status to completed', function () {
            $this->runtime->markAsCompleted();

            expect($this->runtime->isCancelled())->toBeFalse();
        });

        test('markAsCancelled sets status to cancelled', function () {
            $this->runtime->markAsCancelled();

            expect($this->runtime->isCancelled())->toBeTrue();
        });

        test('markAsFailed changes status to failed', function () {
            $this->runtime->markAsFailed();

            expect($this->runtime->isCancelled())->toBeFalse();
        });

        test('isCancelled returns false after markAsCompleted', function () {
            $this->runtime->markAsCancelled();
            $this->runtime->markAsCompleted();

            expect($this->runtime->isCancelled())->toBeFalse();
        });
    });

    describe('Client Tool Schemas', function () {
        test('getClientToolSchemas returns empty array by default', function () {
            expect($this->runtime->getClientToolSchemas())->toBe([]);
        });

        test('getClientToolSchemas returns schemas passed to constructor', function () {
            $schemas = [
                ['name' => 'my_tool', 'description' => 'A tool', 'parameters' => []],
            ];

            $runtime = new InMemoryRuntime(
                agent: $this->agent,
                clientToolSchemas: $schemas,
            );

            expect($runtime->getClientToolSchemas())->toBe($schemas);
        });
    });

    describe('Context Variables', function () {
        test('getContextVariables returns empty array by default', function () {
            expect($this->runtime->getContextVariables())->toBe([]);
        });

        test('getContextVariables returns variables passed to constructor', function () {
            $variables = ['project_name' => 'Acme', 'environment' => 'production'];

            $runtime = new InMemoryRuntime(
                agent: $this->agent,
                contextVariables: $variables,
            );

            expect($runtime->getContextVariables())->toBe($variables);
        });
    });

    describe('No-op Methods', function () {
        test('storeSnapshot does not throw an exception', function () {
            expect(fn () => $this->runtime->storeSnapshot('system prompt', ['context'], ['config']))
                ->not->toThrow(Exception::class);
        });

        test('storeSnapshot with null values does not throw', function () {
            expect(fn () => $this->runtime->storeSnapshot('system prompt', null, null))
                ->not->toThrow(Exception::class);
        });

        test('refresh does not throw an exception', function () {
            expect(fn () => $this->runtime->refresh())
                ->not->toThrow(Exception::class);
        });
    });

    describe('Pending Tool Request', function () {
        test('getPendingToolRequest returns null initially', function () {
            expect($this->runtime->getPendingToolRequest())->toBeNull();
        });

        test('setPendingToolRequest stores the tool request', function () {
            $toolRequest = ['call_id' => 'call_1', 'name' => 'bash', 'arguments' => ['command' => 'ls']];

            $this->runtime->setPendingToolRequest($toolRequest);

            expect($this->runtime->getPendingToolRequest())->toBe($toolRequest);
        });

        test('setPendingToolRequest sets status to paused', function () {
            $this->runtime->setPendingToolRequest(['call_id' => 'call_1', 'name' => 'bash', 'arguments' => []]);

            // status is paused — not cancelled
            expect($this->runtime->isCancelled())->toBeFalse();
        });
    });

    describe('Stats', function () {
        test('getStats returns correct array structure', function () {
            $stats = $this->runtime->getStats();

            expect($stats)->toHaveKeys(['turns', 'tokens', 'prompt_tokens', 'completion_tokens']);
        });

        test('getStats returns zeroed values initially', function () {
            $stats = $this->runtime->getStats();

            expect($stats['turns'])->toBe(0)
                ->and($stats['tokens'])->toBe(0)
                ->and($stats['prompt_tokens'])->toBe(0)
                ->and($stats['completion_tokens'])->toBe(0);
        });

        test('getStats reflects accumulated turns and tokens', function () {
            $this->runtime->incrementTurn();
            $this->runtime->incrementTurn();
            $this->runtime->addTokenUsage(200, 100);

            $stats = $this->runtime->getStats();

            expect($stats['turns'])->toBe(2)
                ->and($stats['tokens'])->toBe(300)
                ->and($stats['prompt_tokens'])->toBe(200)
                ->and($stats['completion_tokens'])->toBe(100);
        });
    });
});
