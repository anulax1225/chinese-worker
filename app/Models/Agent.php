<?php

namespace App\Models;

use App\DTOs\ModelConfig;
use App\Events\ContextFilterOptionsInvalid;
use App\Exceptions\InvalidOptionsException;
use App\Services\ContextFilter\ContextFilterManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

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
        'context_variables',
        'config',
        'status',
        'ai_backend',
        'model_config',
        'metadata',
        'context_strategy',
        'context_options',
        'context_threshold',
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
            'model_config' => 'array',
            'metadata' => 'array',
            'context_options' => 'array',
            'context_threshold' => 'float',
        ];
    }

    /**
     * Bootstrap the model.
     */
    protected static function booted(): void
    {
        static::saving(function (Agent $agent) {
            // Validate threshold range
            if ($agent->context_threshold !== null) {
                if ($agent->context_threshold < 0 || $agent->context_threshold > 1) {
                    throw new InvalidArgumentException('context_threshold must be between 0 and 1');
                }
            }

            // Validate strategy options match strategy
            if ($agent->context_strategy !== null && $agent->context_options !== null) {
                try {
                    $manager = app(ContextFilterManager::class);
                    $strategy = $manager->resolve($agent->context_strategy);
                    $strategy->validateOptions($agent->context_options);
                } catch (InvalidOptionsException $e) {
                    event(new ContextFilterOptionsInvalid(
                        agentId: $agent->id ?? 0,
                        strategyName: $agent->context_strategy,
                        options: $agent->context_options,
                        errorMessage: $e->getMessage(),
                    ));

                    throw $e;
                }
            }
        });
    }

    /**
     * Get the model configuration as a DTO.
     */
    public function getModelConfigDto(): ModelConfig
    {
        return ModelConfig::fromArray($this->model_config ?? []);
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
            'agent_name' => $this->name ?? '',
            'agent_description' => $this->description ?? '',
        ], $this->context_variables ?? []);
    }
}
