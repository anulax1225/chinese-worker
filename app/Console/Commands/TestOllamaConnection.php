<?php

namespace App\Console\Commands;

use App\Services\AIBackendManager;
use Illuminate\Console\Command;
use Throwable;

class TestOllamaConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ollama:test {--backend=ollama : The backend to test}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test connection to Ollama backend and list available models';

    /**
     * Execute the console command.
     */
    public function handle(AIBackendManager $manager): int
    {
        $backendName = $this->option('backend');

        $this->info("Testing connection to '{$backendName}' backend...");
        $this->newLine();

        try {
            $backend = $manager->driver($backendName);

            // Test listing models
            $this->info('Fetching available models...');
            $models = $backend->listModels();

            if (empty($models)) {
                $this->warn('No models found. You may need to pull a model first.');
                $this->info('Run: sail exec ollama ollama pull llama3.1');

                return self::FAILURE;
            }

            $this->info('Available models:');
            $this->newLine();

            $tableData = [];
            foreach ($models as $model) {
                $tableData[] = [
                    'Name' => $model['name'],
                    'Size' => $this->formatBytes($model['size'] ?? 0),
                    'Modified' => $model['modified_at'] ?? 'Unknown',
                ];
            }

            $this->table(['Name', 'Size', 'Modified'], $tableData);

            $this->newLine();
            $this->info('✓ Connection successful!');
            $this->info("✓ Backend '{$backendName}' is working properly.");

            // Display capabilities
            $capabilities = $backend->getCapabilities();
            $this->newLine();
            $this->info('Backend capabilities:');
            foreach ($capabilities as $capability => $supported) {
                $status = $supported ? '✓' : '✗';
                $this->line("  {$status} {$capability}");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Connection failed!');
            $this->error($e->getMessage());
            $this->newLine();
            $this->warn('Make sure Ollama is running and accessible.');
            $this->info('Check configuration in config/ai.php');

            return self::FAILURE;
        }
    }

    /**
     * Format bytes to human readable format.
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
