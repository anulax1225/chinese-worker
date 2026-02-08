<?php

namespace App\DTOs\Document;

class CleaningResult
{
    /**
     * Create a new cleaning result instance.
     *
     * @param  array<string>  $stepsApplied
     */
    public function __construct(
        public readonly string $text,
        public readonly array $stepsApplied,
        public readonly int $charactersBefore,
        public readonly int $charactersAfter,
    ) {}

    /**
     * Get the percentage of characters removed.
     */
    public function reductionPercentage(): float
    {
        if ($this->charactersBefore === 0) {
            return 0.0;
        }

        return round(
            (($this->charactersBefore - $this->charactersAfter) / $this->charactersBefore) * 100,
            2
        );
    }

    /**
     * Get the number of characters removed.
     */
    public function charactersRemoved(): int
    {
        return $this->charactersBefore - $this->charactersAfter;
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'steps_applied' => $this->stepsApplied,
            'characters_before' => $this->charactersBefore,
            'characters_after' => $this->charactersAfter,
            'characters_removed' => $this->charactersRemoved(),
            'reduction_percentage' => $this->reductionPercentage(),
        ];
    }
}
