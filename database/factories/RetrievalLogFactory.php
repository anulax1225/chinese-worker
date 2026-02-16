<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RetrievalLog>
 */
class RetrievalLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'query' => fake()->sentence(),
            'query_expansion' => null,
            'retrieved_chunks' => [],
            'retrieval_strategy' => fake()->randomElement(['dense', 'sparse', 'hybrid']),
            'retrieval_scores' => [],
            'execution_time_ms' => fake()->randomFloat(2, 10, 500),
            'chunks_found' => fake()->numberBetween(0, 20),
            'average_score' => fake()->randomFloat(4, 0.5, 1.0),
            'user_found_helpful' => null,
        ];
    }

    /**
     * Use dense search strategy.
     */
    public function dense(): static
    {
        return $this->state(fn () => [
            'retrieval_strategy' => 'dense',
        ]);
    }

    /**
     * Use sparse search strategy.
     */
    public function sparse(): static
    {
        return $this->state(fn () => [
            'retrieval_strategy' => 'sparse',
        ]);
    }

    /**
     * Use hybrid search strategy.
     */
    public function hybrid(): static
    {
        return $this->state(fn () => [
            'retrieval_strategy' => 'hybrid',
        ]);
    }

    /**
     * Mark retrieval as helpful.
     */
    public function helpful(): static
    {
        return $this->state(fn () => [
            'user_found_helpful' => true,
        ]);
    }

    /**
     * Mark retrieval as not helpful.
     */
    public function notHelpful(): static
    {
        return $this->state(fn () => [
            'user_found_helpful' => false,
        ]);
    }

    /**
     * Create without conversation.
     */
    public function withoutConversation(): static
    {
        return $this->state(fn () => [
            'conversation_id' => null,
        ]);
    }
}
