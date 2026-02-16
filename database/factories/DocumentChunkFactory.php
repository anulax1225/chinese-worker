<?php

namespace Database\Factories;

use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->paragraphs(2, true);

        return [
            'document_id' => Document::factory(),
            'chunk_index' => fake()->numberBetween(0, 100),
            'content' => $content,
            'token_count' => fake()->numberBetween(50, 500),
            'start_offset' => 0,
            'end_offset' => mb_strlen($content),
            'section_title' => fake()->optional(0.3)->sentence(3),
            'metadata' => [],
            'created_at' => now(),
        ];
    }

    /**
     * Indicate that the chunk has overlap metadata.
     */
    public function withOverlap(int $tokens = 50): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'overlap_tokens' => $tokens,
            ]),
        ]);
    }

    /**
     * Indicate that the chunk is the first chunk.
     */
    public function first(): static
    {
        return $this->state(fn (array $attributes) => [
            'chunk_index' => 0,
            'start_offset' => 0,
        ]);
    }

    /**
     * Indicate a specific chunk index.
     */
    public function atIndex(int $index): static
    {
        return $this->state(fn (array $attributes) => [
            'chunk_index' => $index,
        ]);
    }

    /**
     * Indicate that the chunk has a section title.
     */
    public function withSection(string $title): static
    {
        return $this->state(fn (array $attributes) => [
            'section_title' => $title,
        ]);
    }

    /**
     * Indicate that the chunk has an embedding.
     */
    public function withEmbedding(?string $model = null): static
    {
        $model = $model ?? 'text-embedding-3-small';
        $dimensions = $model === 'nomic-embed-text' ? 768 : 1536;

        return $this->state(fn () => [
            'embedding_raw' => array_map(
                fn () => fake()->randomFloat(6, -1, 1),
                range(1, $dimensions)
            ),
            'embedding_model' => $model,
            'embedding_generated_at' => now(),
            'embedding_dimensions' => $dimensions,
            'chunk_type' => 'standard',
            'quality_score' => 1.0,
        ]);
    }

    /**
     * Indicate that the chunk needs embedding (no embedding generated).
     */
    public function needsEmbedding(): static
    {
        return $this->state(fn () => [
            'embedding_raw' => null,
            'embedding_model' => null,
            'embedding_generated_at' => null,
            'embedding_dimensions' => 1536,
        ]);
    }

    /**
     * Indicate the chunk type.
     */
    public function ofType(string $type): static
    {
        return $this->state(fn () => [
            'chunk_type' => $type,
        ]);
    }

    /**
     * Indicate specific content for the chunk.
     */
    public function withContent(string $content): static
    {
        return $this->state(fn () => [
            'content' => $content,
            'token_count' => (int) (mb_strlen($content) / 4),
            'end_offset' => mb_strlen($content),
            'content_hash' => hash('sha256', $content),
        ]);
    }
}
