<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => \App\Models\Agent::factory(),
            'payload' => [
                'input' => fake()->sentence(),
                'parameters' => [
                    'temperature' => fake()->randomFloat(1, 0, 1),
                    'max_tokens' => fake()->numberBetween(100, 2000),
                ],
            ],
            'priority' => fake()->numberBetween(0, 10),
            'scheduled_at' => null,
        ];
    }

    /**
     * Indicate that the task should be scheduled.
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'scheduled_at' => fake()->dateTimeBetween('now', '+7 days'),
        ]);
    }
}
