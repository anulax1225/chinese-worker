<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Execution>
 */
class ExecutionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => \App\Models\Task::factory(),
            'status' => fake()->randomElement(['pending', 'running', 'completed', 'failed']),
            'started_at' => null,
            'completed_at' => null,
            'result' => null,
            'logs' => null,
            'error' => null,
        ];
    }

    /**
     * Indicate that the execution is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
        ]);
    }

    /**
     * Indicate that the execution is running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
            'started_at' => now(),
            'completed_at' => null,
        ]);
    }

    /**
     * Indicate that the execution is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'result' => [
                'content' => fake()->paragraph(),
                'model' => 'llama3.1',
                'tokens_used' => fake()->numberBetween(100, 1000),
                'finish_reason' => 'stop',
            ],
            'logs' => fake()->text(500),
        ]);
    }

    /**
     * Indicate that the execution has failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'error' => fake()->sentence(),
            'logs' => fake()->text(500),
        ]);
    }
}
