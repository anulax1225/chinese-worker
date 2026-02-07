<?php

namespace App\Services\Prompts;

class ContextMerger
{
    /**
     * Merge multiple context arrays with later arrays taking precedence.
     *
     * Precedence order (lowest to highest):
     * 1. System defaults (date, time, app info)
     * 2. Agent defaults (from agents.context_variables)
     * 3. Prompt defaults (from system_prompts.default_values)
     * 4. Agent-prompt overrides (from pivot variable_overrides)
     * 5. Runtime context (conversation-specific data)
     *
     * @param  array<string, mixed>  ...$contexts
     * @return array<string, mixed>
     */
    public function merge(array ...$contexts): array
    {
        return array_reduce($contexts, function (array $merged, array $context): array {
            // Filter out null values before merging
            $filtered = array_filter($context, fn ($value): bool => $value !== null);

            return array_merge($merged, $filtered);
        }, []);
    }
}
