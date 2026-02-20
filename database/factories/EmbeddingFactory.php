<?php

namespace Database\Factories;

use App\Enums\EmbeddingStatus;
use App\Models\Embedding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Embedding>
 */
class EmbeddingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->sentence();
        $model = 'qwen3-embedding:4b';

        return [
            'user_id' => User::factory(),
            'text' => $text,
            'text_hash' => Embedding::hashText($text, $model),
            'model' => $model,
            'status' => EmbeddingStatus::Pending,
            'embedding_raw' => null,
            'error' => null,
            'dimensions' => null,
        ];
    }

    /**
     * Indicate that the embedding is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EmbeddingStatus::Pending,
        ]);
    }

    /**
     * Indicate that the embedding is processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EmbeddingStatus::Processing,
        ]);
    }

    /**
     * Indicate that the embedding is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EmbeddingStatus::Completed,
            'embedding_raw' => array_map(fn () => fake()->randomFloat(6, -1, 1), range(1, 1536)),
            'dimensions' => 1536,
        ]);
    }

    /**
     * Indicate that the embedding processing failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => EmbeddingStatus::Failed,
            'error' => 'Embedding generation failed: '.fake()->sentence(),
        ]);
    }
}
