<?php

namespace App\Console\Commands;

use App\Models\SystemPrompt;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallSystemPrompt extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'prompt:install
                            {path : Path to the blade template file}
                            {--name= : Display name for the prompt (defaults to filename)}
                            {--slug= : Unique slug identifier (defaults to slugified filename)}
                            {--inactive : Create the prompt as inactive}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install or update a system prompt from a blade template file';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->argument('path');

        if (! file_exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $template = file_get_contents($path);

        if ($template === false) {
            $this->error("Could not read file: {$path}");

            return self::FAILURE;
        }

        // Derive name and slug from filename if not provided
        // Strip .blade extension if present (e.g., "my-prompt.blade.php" -> "my-prompt")
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $filename = preg_replace('/\.blade$/', '', $filename);
        $name = $this->option('name') ?? Str::title(str_replace(['-', '_'], ' ', $filename));
        $slug = $this->option('slug') ?? Str::slug($filename);

        $prompt = SystemPrompt::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'template' => $template,
                'is_active' => ! $this->option('inactive'),
            ]
        );

        $action = $prompt->wasRecentlyCreated ? 'Created' : 'Updated';
        $this->info("{$action} system prompt: {$name} ({$slug})");

        return self::SUCCESS;
    }
}
