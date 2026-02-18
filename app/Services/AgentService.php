<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Support\Facades\DB;

class AgentService
{
    /**
     * Create a new agent.
     */
    public function create(array $data): Agent
    {
        return DB::transaction(function () use ($data) {
            $systemPromptIds = $data['system_prompt_ids'] ?? [];
            unset($data['system_prompt_ids']);

            $agent = Agent::query()->create($data);

            if (! empty($systemPromptIds)) {
                $this->syncSystemPrompts($agent, $systemPromptIds);
            }

            return $agent->load(['systemPrompts']);
        });
    }

    /**
     * Update an existing agent.
     */
    public function update(Agent $agent, array $data): Agent
    {
        return DB::transaction(function () use ($agent, $data) {
            $systemPromptIds = $data['system_prompt_ids'] ?? null;
            unset($data['system_prompt_ids']);

            $agent->update($data);

            if ($systemPromptIds !== null) {
                $this->syncSystemPrompts($agent, $systemPromptIds);
            }

            return $agent->fresh(['systemPrompts']);
        });
    }

    /**
     * Delete an agent.
     */
    public function delete(Agent $agent): bool
    {
        return $agent->delete();
    }

    /**
     * Sync system prompts with order.
     *
     * @param  array<int>  $systemPromptIds  Array of system prompt IDs in order
     */
    public function syncSystemPrompts(Agent $agent, array $systemPromptIds): void
    {
        $pivotData = [];
        foreach ($systemPromptIds as $order => $id) {
            $pivotData[$id] = ['order' => $order];
        }
        $agent->systemPrompts()->sync($pivotData);
    }
}
