<?php

namespace App\Services\Prompts;

use Illuminate\Support\Facades\Blade;
use Illuminate\View\Factory as ViewFactory;
use Throwable;

class BladePromptRenderer
{
    /**
     * Dangerous Blade directives that should be stripped from templates.
     * These could allow arbitrary code execution or file access.
     */
    protected const DANGEROUS_DIRECTIVES = [
        '@php',
        '@endphp',
        '@include',
        '@includeIf',
        '@includeWhen',
        '@includeUnless',
        '@includeFirst',
        '@each',
        '@inject',
        '@extends',
        '@section',
        '@endsection',
        '@yield',
        '@parent',
        '@stack',
        '@push',
        '@endpush',
        '@prepend',
        '@endprepend',
        '@once',
        '@endonce',
        '@verbatim',
        '@endverbatim',
        '@component',
        '@endcomponent',
        '@slot',
        '@endslot',
        '@aware',
        '@use',
    ];

    /**
     * Patterns for dangerous constructs that should be stripped.
     */
    protected const DANGEROUS_PATTERNS = [
        // Unescaped output {!! ... !!}
        '/\{!!\s*.*?\s*!!\}/s',
        // Raw PHP tags
        '/<\?php.*?\?>/s',
        '/<\?=.*?\?>/s',
        // @php directive blocks
        '/@php\b.*?@endphp/s',
    ];

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

        // Sanitize template to remove dangerous directives
        $safeTemplate = $this->sanitize($template);

        try {
            return Blade::render($safeTemplate, $variables);
        } catch (Throwable $e) {
            throw new \RuntimeException(
                "Failed to render prompt template: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    /**
     * Sanitize a template by removing dangerous Blade directives.
     */
    public function sanitize(string $template): string
    {
        // Remove dangerous patterns first (multi-line constructs)
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            $template = preg_replace($pattern, '', $template) ?? $template;
        }

        // Remove dangerous directives (including with arguments like @include('view'))
        foreach (self::DANGEROUS_DIRECTIVES as $directive) {
            // Match directive with optional arguments: @directive or @directive('args') or @directive($var)
            $escapedDirective = preg_quote($directive, '/');
            $pattern = '/'.$escapedDirective.'(\s*\([^)]*\))?/';
            $template = preg_replace($pattern, '', $template) ?? $template;
        }

        return $template;
    }

    /**
     * Check if a template contains any dangerous directives.
     *
     * @return array<string> List of dangerous directives found
     */
    public function detectDangerousDirectives(string $template): array
    {
        $found = [];

        foreach (self::DANGEROUS_DIRECTIVES as $directive) {
            if (str_contains($template, $directive)) {
                $found[] = $directive;
            }
        }

        // Check for unescaped output
        if (preg_match('/\{!!\s*.*?\s*!!\}/s', $template)) {
            $found[] = '{!! !!}';
        }

        // Check for raw PHP tags
        if (preg_match('/<\?php/i', $template) || preg_match('/<\?=/', $template)) {
            $found[] = '<?php';
        }

        return array_unique($found);
    }
}
