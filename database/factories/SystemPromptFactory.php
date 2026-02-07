<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SystemPrompt>
 */
class SystemPromptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'template' => 'You are a helpful assistant. Today is {{ $date }}.',
            'required_variables' => [],
            'default_values' => [],
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the prompt is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Create a prompt with specific required variables.
     *
     * @param  array<string>  $variables
     */
    public function withRequiredVariables(array $variables): static
    {
        return $this->state(fn (array $attributes) => [
            'required_variables' => $variables,
        ]);
    }

    /**
     * Create a prompt with specific default values.
     *
     * @param  array<string, mixed>  $defaults
     */
    public function withDefaults(array $defaults): static
    {
        return $this->state(fn (array $attributes) => [
            'default_values' => $defaults,
        ]);
    }
}
