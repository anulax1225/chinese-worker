<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => \App\Models\User::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'code' => fake()->paragraph(),
            'config' => [
                'max_iterations' => fake()->numberBetween(1, 10),
                'timeout' => fake()->numberBetween(30, 300),
            ],
            'status' => fake()->randomElement(['active', 'inactive', 'error']),
            'ai_backend' => fake()->randomElement(['ollama', 'claude', 'openai']),
        ];
    }

    /**
     * Indicate that the agent is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Indicate that the agent is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the agent has an error status.
     */
    public function error(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'error',
        ]);
    }
}
