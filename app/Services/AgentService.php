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
            unset($data['tool_ids']);

            $agent = Agent::query()->create($data);

            if (! empty($toolIds)) {
                $agent->tools()->attach($toolIds);
            }

            return $agent->load('tools');
        });
    }

    /**
     * Update an existing agent.
     */
    public function update(Agent $agent, array $data): Agent
    {
        return DB::transaction(function () use ($agent, $data) {
            $toolIds = $data['tool_ids'] ?? null;
            unset($data['tool_ids']);

            $agent->update($data);

            if ($toolIds !== null) {
                $agent->tools()->sync($toolIds);
            }

            return $agent->fresh(['tools']);
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
}
