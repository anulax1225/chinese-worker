<?php

namespace App\Contracts;

use App\DTOs\Document\StructuredContent;

interface StructureProcessorInterface
{
    /**
     * Get the unique name of this processor.
     */
    public function getName(): string;

    /**
     * Get a human-readable description of what this processor does.
     */
    public function getDescription(): string;

    /**
     * Process the text/content through this structure processor.
     */
    public function process(StructuredContent $content): StructuredContent;

    /**
     * Get the priority order (lower = runs first).
     */
    public function getPriority(): int;
}
