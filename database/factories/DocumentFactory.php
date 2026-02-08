<?php

namespace Database\Factories;

use App\Enums\DocumentSourceType;
use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Document>
 */
class DocumentFactory extends Factory
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
            'file_id' => null,
            'title' => fake()->sentence(3),
            'source_type' => DocumentSourceType::Upload,
            'source_path' => 'documents/'.fake()->uuid().'.txt',
            'mime_type' => fake()->randomElement(['text/plain', 'text/markdown', 'application/pdf']),
            'file_size' => fake()->numberBetween(100, 1000000),
            'status' => DocumentStatus::Pending,
            'error_message' => null,
            'metadata' => [],
        ];
    }

    /**
     * Indicate that the document is ready.
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Ready,
            'processing_started_at' => now()->subMinutes(5),
            'processing_completed_at' => now(),
        ]);
    }

    /**
     * Indicate that the document failed processing.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DocumentStatus::Failed,
            'error_message' => 'Processing failed: '.fake()->sentence(),
            'processing_started_at' => now()->subMinutes(5),
        ]);
    }

    /**
     * Indicate that the document is processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => fake()->randomElement([
                DocumentStatus::Extracting,
                DocumentStatus::Cleaning,
                DocumentStatus::Normalizing,
                DocumentStatus::Chunking,
            ]),
            'processing_started_at' => now()->subMinutes(2),
        ]);
    }

    /**
     * Indicate that the document source is a URL.
     */
    public function fromUrl(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => DocumentSourceType::Url,
            'source_path' => fake()->url(),
            'metadata' => ['original_url' => fake()->url()],
        ]);
    }

    /**
     * Indicate that the document source is pasted text.
     */
    public function fromPaste(): static
    {
        return $this->state(fn (array $attributes) => [
            'source_type' => DocumentSourceType::Paste,
            'source_path' => sys_get_temp_dir().'/'.fake()->uuid().'.txt',
            'mime_type' => 'text/plain',
            'metadata' => ['pasted_at' => now()->toIso8601String()],
        ]);
    }

    /**
     * Attach a file to the document.
     */
    public function withFile(): static
    {
        return $this->state(fn (array $attributes) => [
            'file_id' => \App\Models\File::factory(),
        ]);
    }
}
