<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageEmbedding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MessageEmbedding>
 */
class MessageEmbeddingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = $this->faker->paragraph();

        return [
            'message_id' => Message::factory(),
            'conversation_id' => fn (array $attributes) => Message::find($attributes['message_id'])?->conversation_id
                ?? Conversation::factory(),
            'embedding_raw' => $this->generateFakeEmbedding(),
            'embedding_model' => 'text-embedding-3-small',
            'embedding_dimensions' => 1536,
            'embedding_generated_at' => now(),
            'sparse_vector' => $this->generateFakeSparseVector($content),
            'content_hash' => MessageEmbedding::hashContent($content),
            'token_count' => $this->faker->numberBetween(50, 500),
            'quality_score' => 1.0,
            'access_count' => 0,
            'last_accessed_at' => null,
        ];
    }

    /**
     * State: pending embedding (not yet generated).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'embedding_raw' => null,
            'embedding_generated_at' => null,
            'sparse_vector' => null,
        ]);
    }

    /**
     * State: frequently accessed.
     */
    public function frequentlyAccessed(): static
    {
        return $this->state(fn (array $attributes) => [
            'access_count' => $this->faker->numberBetween(10, 100),
            'last_accessed_at' => $this->faker->dateTimeBetween('-1 week', 'now'),
        ]);
    }

    /**
     * Generate a fake embedding vector.
     *
     * @return array<int, float>
     */
    protected function generateFakeEmbedding(int $dimensions = 1536): array
    {
        $embedding = [];
        for ($i = 0; $i < $dimensions; $i++) {
            $embedding[] = $this->faker->randomFloat(6, -1, 1);
        }

        // Normalize the vector
        $magnitude = sqrt(array_sum(array_map(fn ($v) => $v * $v, $embedding)));
        if ($magnitude > 0) {
            $embedding = array_map(fn ($v) => $v / $magnitude, $embedding);
        }

        return $embedding;
    }

    /**
     * Generate a fake sparse vector from content.
     *
     * @return array<string, float>
     */
    protected function generateFakeSparseVector(string $content): array
    {
        $words = array_filter(explode(' ', strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $content))));
        $wordCounts = array_count_values($words);
        $sparse = [];

        foreach ($wordCounts as $word => $count) {
            if (strlen($word) > 2) {
                $sparse[$word] = $count / count($words);
            }
        }

        return $sparse;
    }
}
