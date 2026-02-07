<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class AgentSystemPrompt extends Pivot
{
    protected $table = 'agent_system_prompt';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'variable_overrides' => 'array',
        ];
    }
}
