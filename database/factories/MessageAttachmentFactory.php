<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MessageAttachment>
 */
class MessageAttachmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory()->user(),
            'type' => MessageAttachment::TYPE_IMAGE,
            'filename' => $this->faker->word().'.png',
            'mime_type' => 'image/png',
            'storage_path' => 'attachments/'.$this->faker->uuid().'.png',
            'metadata' => null,
        ];
    }

    /**
     * Configure the attachment as an image.
     */
    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MessageAttachment::TYPE_IMAGE,
            'mime_type' => $this->faker->randomElement(['image/png', 'image/jpeg', 'image/webp']),
        ]);
    }

    /**
     * Configure the attachment as a document.
     */
    public function document(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MessageAttachment::TYPE_DOCUMENT,
            'mime_type' => $this->faker->randomElement(['application/pdf', 'text/plain', 'application/json']),
            'filename' => $this->faker->word().'.'.$this->faker->randomElement(['pdf', 'txt', 'json']),
        ]);
    }
}
