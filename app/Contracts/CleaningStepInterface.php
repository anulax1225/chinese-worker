<?php

namespace App\Contracts;

interface CleaningStepInterface
{
    /**
     * Get the unique name of this cleaning step.
     * This should match the name in config('document.cleaning.enabled_steps').
     */
    public function getName(): string;

    /**
     * Get a human-readable description of what this step does.
     */
    public function getDescription(): string;

    /**
     * Process the text through this cleaning step.
     *
     * @return array{text: string, changes_made: int}
     */
    public function clean(string $text): array;

    /**
     * Get the priority order (lower = runs first).
     */
    public function getPriority(): int;
}
