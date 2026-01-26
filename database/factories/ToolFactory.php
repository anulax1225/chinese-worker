<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tool>
 */
class ToolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['api', 'function', 'command']);

        return [
            'user_id' => \App\Models\User::factory(),
            'name' => fake()->words(2, true),
            'type' => $type,
            'config' => $this->getConfigForType($type),
        ];
    }

    /**
     * Get configuration based on tool type.
     */
    protected function getConfigForType(string $type): array
    {
        return match ($type) {
            'api' => [
                'url' => fake()->url(),
                'method' => fake()->randomElement(['GET', 'POST', 'PUT', 'DELETE']),
                'headers' => ['Content-Type' => 'application/json'],
            ],
            'function' => [
                'code' => 'function execute($input) { return $input; }',
            ],
            'command' => [
                'command' => 'echo {{input}}',
            ],
            default => [],
        };
    }

    /**
     * Indicate that the tool is an API tool.
     */
    public function api(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'api',
            'config' => [
                'url' => fake()->url(),
                'method' => 'POST',
                'headers' => ['Content-Type' => 'application/json'],
            ],
        ]);
    }

    /**
     * Indicate that the tool is a function tool.
     */
    public function function(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'function',
            'config' => [
                'code' => 'function execute($input) { return strtoupper($input); }',
            ],
        ]);
    }

    /**
     * Indicate that the tool is a command tool.
     */
    public function command(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'command',
            'config' => [
                'command' => 'echo {{input}}',
            ],
        ]);
    }
}
