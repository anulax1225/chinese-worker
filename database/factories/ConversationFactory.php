<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'user_id' => User::factory(),
            'status' => 'active',
            'messages' => [],
            'metadata' => [],
            'turn_count' => 0,
            'total_tokens' => 0,
            'started_at' => now(),
            'last_activity_at' => now(),
            'waiting_for' => 'none',
        ];
    }

    /**
     * Indicate that the conversation is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the conversation is waiting for a tool result.
     */
    public function waitingForTool(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paused',
            'waiting_for' => 'tool_result',
            'pending_tool_request' => [
                'id' => 'call_'.fake()->uuid(),
                'name' => 'bash',
                'arguments' => ['command' => 'ls'],
            ],
        ]);
    }
}
