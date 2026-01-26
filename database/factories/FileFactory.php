<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\File>
 */
class FileFactory extends Factory
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
            'path' => 'files/'.fake()->randomElement(['input', 'output', 'temp']).'/'.fake()->uuid().'.txt',
            'type' => fake()->randomElement(['input', 'output', 'temp']),
            'size' => fake()->numberBetween(100, 1000000),
            'mime_type' => fake()->randomElement(['text/plain', 'application/json', 'text/csv', 'application/pdf']),
        ];
    }

    /**
     * Indicate that the file is an input file.
     */
    public function input(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'input',
            'path' => 'files/input/'.fake()->uuid().'.txt',
        ]);
    }

    /**
     * Indicate that the file is an output file.
     */
    public function output(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'output',
            'path' => 'files/output/'.fake()->uuid().'.txt',
        ]);
    }

    /**
     * Indicate that the file is a temporary file.
     */
    public function temp(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'temp',
            'path' => 'files/temp/'.fake()->uuid().'.txt',
        ]);
    }
}
