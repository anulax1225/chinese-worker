<?php

namespace App\Services\Prompts\Contracts;

interface ContextBuilderInterface
{
    /**
     * Build context variables for system prompt rendering.
     *
     * @return array<string, mixed>
     */
    public function build(): array;
}
