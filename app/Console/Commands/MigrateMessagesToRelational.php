<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageToolCall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateMessagesToRelational extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'messages:migrate
                            {--dry-run : Show what would be migrated without actually doing it}
                            {--chunk=100 : Number of conversations to process at a time}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate messages from JSON column to relational tables';

    protected int $migratedConversations = 0;

    protected int $migratedMessages = 0;

    protected int $migratedToolCalls = 0;

    protected int $migratedAttachments = 0;

    protected int $skippedConversations = 0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        if ($isDryRun) {
            $this->info('DRY RUN - No changes will be made');
        }

        $totalConversations = Conversation::whereNotNull('messages')
            ->where('messages', '!=', '[]')
            ->count();

        if ($totalConversations === 0) {
            $this->info('No conversations with JSON messages found to migrate.');

            return self::SUCCESS;
        }

        $this->info("Found {$totalConversations} conversations with JSON messages to migrate.");
        $this->newLine();

        $progressBar = $this->output->createProgressBar($totalConversations);
        $progressBar->start();

        Conversation::whereNotNull('messages')
            ->where('messages', '!=', '[]')
            ->chunkById($chunkSize, function ($conversations) use ($isDryRun, $progressBar) {
                foreach ($conversations as $conversation) {
                    $this->migrateConversation($conversation, $isDryRun);
                    $progressBar->advance();
                }
            });

        $progressBar->finish();
        $this->newLine(2);

        $this->displaySummary($isDryRun);

        return self::SUCCESS;
    }

    /**
     * Migrate a single conversation's messages.
     */
    protected function migrateConversation(Conversation $conversation, bool $isDryRun): void
    {
        $jsonMessages = $conversation->messages;

        if (empty($jsonMessages) || ! is_array($jsonMessages)) {
            $this->skippedConversations++;

            return;
        }

        // Check if already migrated (has relational messages)
        if ($conversation->conversationMessages()->exists()) {
            $this->skippedConversations++;

            return;
        }

        if ($isDryRun) {
            $this->countMessages($jsonMessages);
            $this->migratedConversations++;

            return;
        }

        try {
            DB::transaction(function () use ($conversation, $jsonMessages) {
                foreach ($jsonMessages as $position => $messageData) {
                    $this->migrateMessage($conversation, $messageData, $position);
                }
            });

            $this->migratedConversations++;
        } catch (\Exception $e) {
            Log::error('Failed to migrate conversation messages', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $this->error("Failed to migrate conversation {$conversation->id}: {$e->getMessage()}");
        }
    }

    /**
     * Migrate a single message.
     *
     * @param  array<string, mixed>  $data
     */
    protected function migrateMessage(Conversation $conversation, array $data, int $position): void
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'position' => $position,
            'role' => $data['role'],
            'name' => $data['name'] ?? null,
            'content' => $data['content'] ?? '',
            'thinking' => $data['thinking'] ?? null,
            'token_count' => $data['token_count'] ?? null,
            'tool_call_id' => $data['tool_call_id'] ?? null,
            'counted_at' => isset($data['counted_at']) ? now()->parse($data['counted_at']) : null,
        ]);

        $this->migratedMessages++;

        // Migrate tool calls
        if (! empty($data['tool_calls']) && is_array($data['tool_calls'])) {
            foreach ($data['tool_calls'] as $tcPosition => $toolCallData) {
                $this->migrateToolCall($message, $toolCallData, $tcPosition);
            }
        }

        // Migrate images as attachments
        if (! empty($data['images']) && is_array($data['images'])) {
            foreach ($data['images'] as $imagePath) {
                $this->migrateImageAttachment($message, $imagePath);
            }
        }
    }

    /**
     * Migrate a tool call.
     *
     * @param  array<string, mixed>  $data
     */
    protected function migrateToolCall(Message $message, array $data, int $position): void
    {
        MessageToolCall::create([
            'id' => $data['call_id'] ?? $data['id'] ?? uniqid('call_'),
            'message_id' => $message->id,
            'function_name' => $data['name'],
            'arguments' => $data['arguments'] ?? [],
            'position' => $position,
        ]);

        $this->migratedToolCalls++;
    }

    /**
     * Migrate an image path to attachment.
     */
    protected function migrateImageAttachment(Message $message, string $imagePath): void
    {
        MessageAttachment::create([
            'message_id' => $message->id,
            'type' => MessageAttachment::TYPE_IMAGE,
            'mime_type' => $this->guessMimeType($imagePath),
            'storage_path' => $imagePath,
        ]);

        $this->migratedAttachments++;
    }

    /**
     * Count messages for dry run.
     *
     * @param  array<array<string, mixed>>  $messages
     */
    protected function countMessages(array $messages): void
    {
        foreach ($messages as $message) {
            $this->migratedMessages++;

            if (! empty($message['tool_calls']) && is_array($message['tool_calls'])) {
                $this->migratedToolCalls += count($message['tool_calls']);
            }

            if (! empty($message['images']) && is_array($message['images'])) {
                $this->migratedAttachments += count($message['images']);
            }
        }
    }

    /**
     * Guess MIME type from file path.
     */
    protected function guessMimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };
    }

    /**
     * Display migration summary.
     */
    protected function displaySummary(bool $isDryRun): void
    {
        $action = $isDryRun ? 'Would migrate' : 'Migrated';

        $this->info("{$action}:");
        $this->table(
            ['Item', 'Count'],
            [
                ['Conversations', $this->migratedConversations],
                ['Messages', $this->migratedMessages],
                ['Tool Calls', $this->migratedToolCalls],
                ['Attachments', $this->migratedAttachments],
                ['Skipped (already migrated or empty)', $this->skippedConversations],
            ]
        );

        if (! $isDryRun && $this->migratedConversations > 0) {
            $this->newLine();
            $this->info('Migration completed successfully!');
            $this->warn('Remember to create a migration to drop the conversations.messages column after verifying the data.');
        }
    }
}
