<?php

namespace App\Services\AgentLoop;

use App\DTOs\AgentLoopResult;
use App\DTOs\ChatMessage;
use App\DTOs\ToolCall;
use App\Models\Agent;
use App\Services\AIBackendManager;
use Illuminate\Support\Facades\Log;

class AgentLoopService
{
    public function __construct(
        protected AIBackendManager $backendManager,
        protected BuiltinToolExecutor $toolExecutor
    ) {}

    /**
     * Execute an agent with the agentic loop.
     *
     * @param  array<string, mixed>  $context
     */
    public function execute(Agent $agent, array $context, ?callable $onChunk = null): AgentLoopResult
    {
        $maxTurns = $context['max_turns'] ?? config('agent.max_turns', 25);
        $onToolError = $context['on_tool_error'] ?? config('agent.on_tool_error', 'stop');

        // Get the AI backend
        $backend = $this->backendManager->driver($agent->ai_backend);

        // Initialize conversation history
        $messages = $context['messages'] ?? [];
        $toolResults = [];
        $turn = 0;
        $lastResponse = null;

        // Get agent tools
        $agentTools = $agent->tools->all();

        // Build tools list including builtins
        $tools = $this->toolExecutor->getAllToolSchemas($agentTools, true);

        Log::info('Starting agent loop', [
            'agent' => $agent->name,
            'max_turns' => $maxTurns,
            'tools_count' => count($tools),
        ]);

        while ($turn < $maxTurns) {
            $turn++;

            Log::info("Agent loop turn {$turn}/{$maxTurns}");

            try {
                // Build context for this turn
                $turnContext = array_merge($context, [
                    'messages' => $messages,
                    'tools' => $tools,
                ]);

                // Execute AI call
                if ($onChunk !== null) {
                    $response = $backend->streamExecute($agent, $turnContext, $onChunk);
                } else {
                    $response = $backend->execute($agent, $turnContext);
                }

                $lastResponse = $response;

                // Check if the AI wants to stop
                if (! $response->hasToolCalls()) {
                    Log::info('Agent loop completed', [
                        'turns' => $turn,
                        'finish_reason' => $response->finishReason,
                    ]);

                    return AgentLoopResult::completed($response, $messages, $turn, $toolResults);
                }

                // Process tool calls
                $toolCallMessages = [];
                foreach ($response->toolCalls as $toolCall) {
                    $result = $this->executeToolCall($toolCall, $agentTools);

                    // Store tool result
                    $toolResults[] = [
                        'tool' => $toolCall->name,
                        'arguments' => $toolCall->arguments,
                        'result' => $result->toArray(),
                        'turn' => $turn,
                    ];

                    // Check for tool error
                    if (! $result->success && $onToolError === 'stop') {
                        Log::warning('Agent loop stopped due to tool error', [
                            'tool' => $toolCall->name,
                            'error' => $result->error,
                        ]);

                        return AgentLoopResult::toolError(
                            $result->error ?? 'Unknown tool error',
                            $lastResponse,
                            $messages,
                            $turn,
                            $toolResults
                        );
                    }

                    // Add tool result message
                    $toolCallMessages[] = ChatMessage::tool($result->toString(), $toolCall->id);
                }

                // Add assistant message with tool calls to history
                $messages[] = ChatMessage::assistant(
                    $response->content,
                    array_map(fn (ToolCall $tc) => $tc->toArray(), $response->toolCalls)
                );

                // Add tool result messages to history
                foreach ($toolCallMessages as $msg) {
                    $messages[] = $msg;
                }

            } catch (\Exception $e) {
                Log::error('Agent loop error', [
                    'turn' => $turn,
                    'error' => $e->getMessage(),
                ]);

                return AgentLoopResult::error(
                    "Loop execution failed: {$e->getMessage()}",
                    $messages,
                    $turn
                );
            }
        }

        Log::warning('Agent loop reached max turns', ['max_turns' => $maxTurns]);

        return AgentLoopResult::maxTurnsReached($lastResponse, $messages, $turn, $toolResults);
    }

    /**
     * Execute a single tool call.
     *
     * @param  array<\App\Models\Tool>  $agentTools
     */
    protected function executeToolCall(ToolCall $toolCall, array $agentTools): \App\DTOs\ToolResult
    {
        Log::info("Executing tool call: {$toolCall->name}", [
            'id' => $toolCall->id,
            'arguments' => $toolCall->arguments,
        ]);

        $result = $this->toolExecutor->execute($toolCall, $agentTools);

        Log::info("Tool call completed: {$toolCall->name}", [
            'success' => $result->success,
            'output_length' => strlen($result->output),
        ]);

        return $result;
    }

    /**
     * Get the tool executor instance.
     */
    public function getToolExecutor(): BuiltinToolExecutor
    {
        return $this->toolExecutor;
    }
}
