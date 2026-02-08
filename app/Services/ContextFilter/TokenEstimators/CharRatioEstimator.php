<?php

declare(strict_types=1);

namespace App\Services\ContextFilter\TokenEstimators;

use App\Contracts\TokenEstimator;
use App\DTOs\ChatMessage;

class CharRatioEstimator implements TokenEstimator
{
    /**
     * Estimate the number of tokens in a message using content-aware char ratios.
     */
    public function estimate(ChatMessage $message): int
    {
        $content = $message->content ?? '';

        if ($content === '') {
            return 0;
        }

        $ratio = $this->detectRatio($content);
        $rawEstimate = (int) ceil(mb_strlen($content) / $ratio);

        // Apply safety margin
        $safetyMargin = (float) config('ai.token_estimation.safety_margin', 0.9);

        return (int) ceil($rawEstimate / $safetyMargin);
    }

    /**
     * This estimator is the default fallback - supports all models.
     */
    public function supports(string $model): bool
    {
        return true;
    }

    /**
     * Detect the appropriate char-per-token ratio based on content type.
     */
    private function detectRatio(string $content): float
    {
        $trimmed = ltrim($content);

        // JSON/structured data (tool calls, tool results)
        if ($this->looksLikeJson($trimmed)) {
            return (float) config('ai.token_estimation.json_chars_per_token', 2.5);
        }

        // Code detection
        if ($this->looksLikeCode($content)) {
            return (float) config('ai.token_estimation.code_chars_per_token', 3.0);
        }

        // Default prose
        return (float) config('ai.token_estimation.default_chars_per_token', 4.0);
    }

    /**
     * Check if content appears to be JSON.
     */
    private function looksLikeJson(string $content): bool
    {
        return str_starts_with($content, '{') || str_starts_with($content, '[');
    }

    /**
     * Check if content appears to be code.
     */
    private function looksLikeCode(string $content): bool
    {
        $codeIndicators = ['=>', '->', '<?php', 'function ', 'class ', 'const ', 'import ', 'export ', 'def ', 'return '];
        $matches = 0;

        foreach ($codeIndicators as $indicator) {
            if (str_contains($content, $indicator)) {
                $matches++;
            }
        }

        return $matches >= 2;
    }
}
