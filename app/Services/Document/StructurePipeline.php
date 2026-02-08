<?php

namespace App\Services\Document;

use App\Contracts\StructureProcessorInterface;
use App\DTOs\Document\StructuredContent;
use Illuminate\Support\Collection;

class StructurePipeline
{
    /** @var Collection<int, StructureProcessorInterface> */
    protected Collection $processors;

    public function __construct()
    {
        $this->processors = collect();
    }

    /**
     * Register a structure processor.
     */
    public function register(StructureProcessorInterface $processor): self
    {
        $this->processors->push($processor);

        return $this;
    }

    /**
     * Get all registered processors.
     *
     * @return Collection<int, StructureProcessorInterface>
     */
    public function getProcessors(): Collection
    {
        return $this->processors;
    }

    /**
     * Get a processor by name.
     */
    public function getProcessor(string $name): ?StructureProcessorInterface
    {
        return $this->processors->first(
            fn (StructureProcessorInterface $processor) => $processor->getName() === $name
        );
    }

    /**
     * Run the structure pipeline on text.
     *
     * @param  array<string>|null  $enabledProcessors  Processor names to run. If null, runs all.
     */
    public function process(string $text, ?array $enabledProcessors = null): StructuredContent
    {
        // Start with a basic StructuredContent
        $content = new StructuredContent(
            text: $text,
            sections: [],
            metadata: [],
        );

        // Get enabled processors
        if ($enabledProcessors === null) {
            try {
                $enabledProcessors = config('document.structure.enabled_processors', []);
            } catch (\Throwable) {
                $enabledProcessors = [];
            }
        }

        // Filter and sort processors by priority
        $processorsToRun = $this->processors
            ->when(
                ! empty($enabledProcessors),
                fn (Collection $processors) => $processors->filter(
                    fn (StructureProcessorInterface $processor) => in_array(
                        $processor->getName(),
                        $enabledProcessors,
                        true
                    )
                )
            )
            ->sortBy(fn (StructureProcessorInterface $processor) => $processor->getPriority());

        // Run each processor
        $processorsApplied = [];
        foreach ($processorsToRun as $processor) {
            $previousText = $content->text;
            $previousSections = $content->sections;

            $content = $processor->process($content);

            // Track if processor made changes
            if ($content->text !== $previousText || $content->sections !== $previousSections) {
                $processorsApplied[] = $processor->getName();
            }
        }

        // Add processing metadata
        $metadata = $content->metadata;
        $metadata['processors_applied'] = $processorsApplied;

        return new StructuredContent(
            text: $content->text,
            sections: $content->sections,
            metadata: $metadata,
        );
    }

    /**
     * Get available processor names.
     *
     * @return array<string>
     */
    public function getAvailableProcessors(): array
    {
        return $this->processors
            ->map(fn (StructureProcessorInterface $processor) => $processor->getName())
            ->values()
            ->toArray();
    }

    /**
     * Get processor descriptions.
     *
     * @return array<string, string>
     */
    public function getProcessorDescriptions(): array
    {
        return $this->processors
            ->mapWithKeys(fn (StructureProcessorInterface $processor) => [
                $processor->getName() => $processor->getDescription(),
            ])
            ->toArray();
    }
}
