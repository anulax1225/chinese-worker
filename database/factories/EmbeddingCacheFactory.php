<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmbeddingCache>
 */
class EmbeddingCacheFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_hash' => hash('sha256', fake()->sentence()),
            'embedding_raw' => array_fill(0, 1536, 0.1),
            'embedding_model' => 'text-embedding-3-small',
            'language' => 'en',
        ];
    }

    /**
     * Use a specific embedding model.
     */
    public function model(string $model): static
    {
        return $this->state(fn (array $attributes) => [
            'embedding_model' => $model,
        ]);
    }

    /**
     * Use Ollama's nomic-embed-text model.
     */
    public function ollama(): static
    {
        return $this->state(fn (array $attributes) => [
            'embedding_model' => 'nomic-embed-text',
            'embedding_raw' => array_fill(0, 768, 0.1),
        ]);
    }

    /**
     * Create embedding from specific text content.
     */
    public function forText(string $text): static
    {
        return $this->state(fn (array $attributes) => [
            'content_hash' => hash('sha256', "{$text}::{$attributes['embedding_model']}"),
        ]);
    }
}
