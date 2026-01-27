<?php

namespace Database\Seeders;

use App\Models\Tool;
use App\Services\AgentLoop\BuiltinToolExecutor;
use App\Services\ToolService;
use Illuminate\Database\Seeder;

class BuiltinToolsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create the executor to get tool definitions
        $executor = new BuiltinToolExecutor(app(ToolService::class));
        $builtinTools = $executor->getBuiltinTools();

        foreach ($builtinTools as $tool) {
            Tool::query()->updateOrCreate(
                [
                    'name' => $tool->getName(),
                    'type' => 'builtin',
                ],
                [
                    'user_id' => null, // System-level tool
                    'config' => [
                        'description' => $tool->getDescription(),
                        'parameters' => $tool->getParameterSchema(),
                    ],
                ]
            );

            $this->command->info("Created/updated builtin tool: {$tool->getName()}");
        }

        $this->command->info('Builtin tools seeding completed.');
    }
}
