<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Message>
 */
class MessageFactory extends Factory
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
            'position' => 0,
            'role' => $this->faker->randomElement([Message::ROLE_USER, Message::ROLE_ASSISTANT]),
            'content' => $this->faker->paragraph(),
            'name' => null,
            'thinking' => null,
            'token_count' => $this->faker->numberBetween(10, 500),
            'tool_call_id' => null,
            'counted_at' => now(),
        ];
    }

    /**
     * Configure the message as a system message.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Message::ROLE_SYSTEM,
            'content' => $this->faker->sentence(),
        ]);
    }

    /**
     * Configure the message as a user message.
     */
    public function user(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Message::ROLE_USER,
        ]);
    }

    /**
     * Configure the message as an assistant message.
     */
    public function assistant(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Message::ROLE_ASSISTANT,
        ]);
    }

    /**
     * Configure the message as a tool response.
     */
    public function tool(string $toolCallId, ?string $name = null): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Message::ROLE_TOOL,
            'tool_call_id' => $toolCallId,
            'name' => $name ?? $this->faker->word(),
        ]);
    }

    /**
     * Configure the message with thinking content.
     */
    public function withThinking(): static
    {
        return $this->state(fn (array $attributes) => [
            'thinking' => $this->faker->paragraph(),
        ]);
    }
}
