<?php

namespace Database\Factories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ConversationSummary>
 */
class ConversationSummaryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $originalTokenCount = fake()->numberBetween(1000, 5000);

        return [
            'conversation_id' => Conversation::factory(),
            'from_position' => 1,
            'to_position' => 10,
            'content' => fake()->paragraphs(2, true),
            'token_count' => (int) ($originalTokenCount * 0.2),
            'backend_used' => 'ollama',
            'model_used' => 'llama3.1',
            'summarized_message_ids' => [],
            'original_token_count' => $originalTokenCount,
            'metadata' => null,
        ];
    }
}
