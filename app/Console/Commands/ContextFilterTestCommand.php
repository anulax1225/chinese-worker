<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\ContextFilter\ContextFilterManager;
use Illuminate\Console\Command;

class ContextFilterTestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'context:test
                            {conversation : The conversation ID to test}
                            {--strategy= : Override the strategy to use}
                            {--max-output=4096 : Max output tokens to reserve}
                            {--tool-tokens=0 : Tool definition tokens to reserve}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test context filtering on a conversation';

    public function __construct(
        private readonly ContextFilterManager $manager,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $conversationId = $this->argument('conversation');
        $conversation = Conversation::find($conversationId);

        if (! $conversation) {
            $this->error("Conversation {$conversationId} not found.");

            return self::FAILURE;
        }

        $strategy = $this->option('strategy');
        $maxOutput = (int) $this->option('max-output');
        $toolTokens = (int) $this->option('tool-tokens');

        $this->info("Testing context filter on conversation {$conversationId}");
        $this->newLine();

        // Get original messages
        $messages = $conversation->getMessages();
        $this->info('Original message count: '.count($messages));
        $this->info('Context limit: '.($conversation->context_limit ?? 'not set'));
        $this->info('Current context usage: '.round($conversation->getContextUsagePercentage(), 1).'%');
        $this->newLine();

        // Override strategy if specified
        if ($strategy) {
            $conversation->agent?->fill(['context_strategy' => $strategy]);
            $this->info("Using strategy: {$strategy}");
        } else {
            $effectiveStrategy = $conversation->agent?->context_strategy
                ?? config('ai.context_filter.default_strategy', 'token_budget');
            $this->info("Using strategy: {$effectiveStrategy}");
        }

        $this->newLine();

        // Run filtering
        $result = $this->manager->filterForConversation(
            conversation: $conversation,
            maxOutputTokens: $maxOutput,
            toolDefinitionTokens: $toolTokens,
        );

        // Display results
        $this->table(
            ['Metric', 'Value'],
            [
                ['Original Count', $result->originalCount],
                ['Filtered Count', $result->filteredCount],
                ['Removed Count', $result->getRemovedCount()],
                ['Strategy Used', $result->strategyUsed],
                ['Duration', round($result->durationMs, 2).' ms'],
            ]
        );

        if ($result->hasRemovedMessages()) {
            $this->newLine();
            $this->warn('Removed message IDs:');
            foreach ($result->removedMessageIds as $id) {
                $this->line("  - {$id}");
            }
        } else {
            $this->newLine();
            $this->info('No messages were filtered.');
        }

        $this->newLine();
        $this->info('Available strategies: '.implode(', ', $this->manager->availableStrategies()));

        return self::SUCCESS;
    }
}
