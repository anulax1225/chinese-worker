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
            $toolIds = $data['tool_ids'] ?? [];
            $systemPromptIds = $data['system_prompt_ids'] ?? [];
            unset($data['tool_ids'], $data['system_prompt_ids']);

            $agent = Agent::query()->create($data);

            if (! empty($toolIds)) {
                $agent->tools()->attach($toolIds);
            }

            if (! empty($systemPromptIds)) {
                $this->syncSystemPrompts($agent, $systemPromptIds);
            }

            return $agent->load(['tools', 'systemPrompts']);
        });
    }

    /**
     * Update an existing agent.
     */
    public function update(Agent $agent, array $data): Agent
    {
        return DB::transaction(function () use ($agent, $data) {
            $toolIds = $data['tool_ids'] ?? null;
            $systemPromptIds = $data['system_prompt_ids'] ?? null;
            unset($data['tool_ids'], $data['system_prompt_ids']);

            $agent->update($data);

            if ($toolIds !== null) {
                $agent->tools()->sync($toolIds);
            }

            if ($systemPromptIds !== null) {
                $this->syncSystemPrompts($agent, $systemPromptIds);
            }

            return $agent->fresh(['tools', 'systemPrompts']);
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
     * Attach tools to an agent.
     */
    public function attachTools(Agent $agent, array $toolIds): void
    {
        $agent->tools()->syncWithoutDetaching($toolIds);
    }

    /**
     * Detach tools from an agent.
     */
    public function detachTools(Agent $agent, array $toolIds): void
    {
        $agent->tools()->detach($toolIds);
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
