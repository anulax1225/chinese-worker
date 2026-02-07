<?php

namespace App\Services\Prompts;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\SystemPrompt;

class PromptAssembler
{
    /**
     * The last context used for assembly (for debugging/snapshots).
     *
     * @var array<string, mixed>
     */
    protected array $lastContext = [];

    public function __construct(
        protected BladePromptRenderer $renderer,
        protected SystemContextBuilder $systemContext,
        protected ContextMerger $merger
    ) {}

    /**
     * Assemble the complete system prompt for an agent.
     */
    public function assemble(Agent $agent, ?Conversation $conversation = null): string
    {
        $prompts = $agent->systemPrompts()
            ->wherePivot('order', '>=', 0)
            ->orderByPivot('order')
            ->get();

        if ($prompts->isEmpty()) {
            // Fallback to legacy agent.code
            return $agent->code ?? '';
        }

        $sections = [];

        foreach ($prompts as $prompt) {
            if (! $prompt->is_active) {
                continue;
            }

            $context = $this->buildContext($agent, $prompt, $conversation);
            $this->lastContext = $context;

            $rendered = $this->renderer->render($prompt->template, $context);
            if (! empty(trim($rendered))) {
                $sections[] = $rendered;
            }
        }

        return implode("\n\n", $sections);
    }

    /**
     * Build the merged context for a specific prompt.
     *
     * @return array<string, mixed>
     */
    protected function buildContext(Agent $agent, SystemPrompt $prompt, ?Conversation $conversation): array
    {
        return $this->merger->merge(
            $this->systemContext->build(),
            $agent->getContextVariables(),
            $prompt->default_values ?? [],
            $prompt->pivot?->variable_overrides ?? [],
            $this->getConversationContext($conversation)
        );
    }

    /**
     * Get conversation-specific context variables.
     *
     * @return array<string, mixed>
     */
    protected function getConversationContext(?Conversation $conversation): array
    {
        if (! $conversation) {
            return [];
        }

        return [
            'conversation_id' => $conversation->id,
            'message_count' => count($conversation->getMessages()),
            'user_name' => $conversation->user?->name,
        ];
    }

    /**
     * Get the last context used for assembly.
     *
     * @return array<string, mixed>
     */
    public function getLastContext(): array
    {
        return $this->lastContext;
    }
}
