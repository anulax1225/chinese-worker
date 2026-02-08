<?php

namespace Database\Factories;

use App\Enums\DocumentStageType;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DocumentStage>
 */
class DocumentStageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'stage' => fake()->randomElement(DocumentStageType::cases()),
            'content' => fake()->paragraphs(3, true),
            'metadata' => [],
            'created_at' => now(),
        ];
    }

    /**
     * Indicate that the stage is extracted.
     */
    public function extracted(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => DocumentStageType::Extracted,
            'metadata' => [
                'extractor' => 'TextExtractor',
                'character_count' => fake()->numberBetween(1000, 50000),
            ],
        ]);
    }

    /**
     * Indicate that the stage is cleaned.
     */
    public function cleaned(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => DocumentStageType::Cleaned,
            'metadata' => [
                'cleaner' => 'StandardCleaner',
                'removed_chars' => fake()->numberBetween(0, 500),
            ],
        ]);
    }

    /**
     * Indicate that the stage is normalized.
     */
    public function normalized(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => DocumentStageType::Normalized,
            'metadata' => [
                'normalizer' => 'WhitespaceNormalizer',
            ],
        ]);
    }

    /**
     * Indicate that the stage is chunked.
     */
    public function chunked(): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => DocumentStageType::Chunked,
            'content' => '',
            'metadata' => [
                'chunker' => 'TokenBasedChunker',
                'chunk_count' => fake()->numberBetween(1, 50),
            ],
        ]);
    }
}
