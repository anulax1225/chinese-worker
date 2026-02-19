<?php

namespace App\Services\Embedding\Writers;

use App\Models\Message;
use App\Models\MessageEmbedding;
use Illuminate\Support\Facades\DB;

class MessageEmbeddingWriter extends AbstractEmbeddingWriter
{
    protected function extractText(mixed $item): string
    {
        /** @var Message $item */
        $prefix = match ($item->role) {
            Message::ROLE_USER => 'User: ',
            Message::ROLE_ASSISTANT => 'Assistant: ',
            default => '',
        };

        return $prefix.($item->content ?? '');
    }

    protected function persistVector(
        mixed $item,
        array $dense,
        array $sparse,
        string $model,
        int $dimensions,
    ): void {
        /** @var Message $item */
        $embedding = MessageEmbedding::updateOrCreate(
            ['message_id' => $item->id],
            [
                'conversation_id' => $item->conversation_id,
                'embedding_raw' => $dense,
                'embedding_model' => $model,
                'embedding_dimensions' => $dimensions,
                'embedding_generated_at' => now(),
                'sparse_vector' => $sparse,
                'content_hash' => MessageEmbedding::hashContent($item->content ?? ''),
                'token_count' => $item->token_count,
            ]
        );

        if ($this->embeddingService->usesPgvector() && $dimensions === 1536) {
            DB::statement(
                'UPDATE message_embeddings SET embedding = ?::vector WHERE id = ?',
                [$this->embeddingService->formatVectorForPgvector($dense), $embedding->id]
            );
        }
    }

    /**
     * Embed all un-embedded user and assistant messages in a conversation.
     */
    public function writeForConversation(int $conversationId, ?string $model = null): void
    {
        $messages = Message::where('conversation_id', $conversationId)
            ->whereIn('role', [Message::ROLE_USER, Message::ROLE_ASSISTANT])
            ->whereNotNull('content')
            ->whereDoesntHave('embedding')
            ->get();

        $this->write($messages, $model);
    }
}
