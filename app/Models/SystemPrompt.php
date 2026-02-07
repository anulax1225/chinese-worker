<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SystemPrompt extends Model
{
    /** @use HasFactory<\Database\Factories\SystemPromptFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'template',
        'required_variables',
        'default_values',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'required_variables' => 'array',
            'default_values' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the agents that use this system prompt.
     */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_system_prompt')
            ->withPivot(['order', 'variable_overrides'])
            ->withTimestamps();
    }
}
