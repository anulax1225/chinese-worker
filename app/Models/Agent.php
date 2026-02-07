<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'code',
        'context_variables',
        'config',
        'status',
        'ai_backend',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_variables' => 'array',
            'config' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the user that owns the agent.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tools assigned to the agent.
     */
    public function tools(): BelongsToMany
    {
        return $this->belongsToMany(Tool::class, 'agent_tools');
    }

    /**
     * Get the conversations for the agent.
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Get the system prompts assigned to the agent.
     */
    public function systemPrompts(): BelongsToMany
    {
        return $this->belongsToMany(SystemPrompt::class, 'agent_system_prompt')
            ->using(AgentSystemPrompt::class)
            ->withPivot(['order', 'variable_overrides'])
            ->withTimestamps();
    }

    /**
     * Get context variables for prompt rendering.
     *
     * @return array<string, mixed>
     */
    public function getContextVariables(): array
    {
        return array_merge([
            'agent_name' => $this->name,
            'agent_description' => $this->description,
        ], $this->context_variables ?? []);
    }
}
