<?php

namespace App\Services\Prompts;

use Illuminate\Support\Facades\Blade;
use Illuminate\View\Factory as ViewFactory;
use Throwable;

class BladePromptRenderer
{
    public function __construct(
        protected ViewFactory $viewFactory
    ) {}

    /**
     * Render a Blade template string with the given variables.
     *
     * @param  array<string, mixed>  $variables
     *
     * @throws \RuntimeException When template rendering fails
     */
    public function render(string $template, array $variables = []): string
    {
        if (empty($template)) {
            return '';
        }

        try {
            return Blade::render($template, $variables);
        } catch (Throwable $e) {
            throw new \RuntimeException(
                "Failed to render prompt template: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
}
