<?php

namespace Database\Factories;

use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MessageToolCall>
 */
class MessageToolCallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => 'call_'.Str::random(24),
            'message_id' => Message::factory()->assistant(),
            'function_name' => $this->faker->randomElement(['web_search', 'read_file', 'execute_command', 'get_weather']),
            'arguments' => ['query' => $this->faker->sentence()],
            'position' => 0,
        ];
    }
}
