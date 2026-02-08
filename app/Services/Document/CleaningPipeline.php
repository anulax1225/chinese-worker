<?php

namespace App\Services\Document;

use App\Contracts\CleaningStepInterface;
use App\DTOs\Document\CleaningResult;
use Illuminate\Support\Collection;

class CleaningPipeline
{
    /** @var Collection<int, CleaningStepInterface> */
    protected Collection $steps;

    public function __construct()
    {
        $this->steps = collect();
    }

    /**
     * Register a cleaning step.
     */
    public function register(CleaningStepInterface $step): self
    {
        $this->steps->push($step);

        return $this;
    }

    /**
     * Get all registered steps.
     *
     * @return Collection<int, CleaningStepInterface>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    /**
     * Get a step by name.
     */
    public function getStep(string $name): ?CleaningStepInterface
    {
        return $this->steps->first(fn (CleaningStepInterface $step) => $step->getName() === $name);
    }

    /**
     * Run the cleaning pipeline on text.
     *
     * @param  array<string>|null  $enabledSteps  Step names to run (from config). If null, runs all steps.
     */
    public function clean(string $text, ?array $enabledSteps = null): CleaningResult
    {
        $charactersBefore = mb_strlen($text);
        $stepsApplied = [];

        // Get enabled steps from config if not provided
        if ($enabledSteps === null) {
            $enabledSteps = config('document.cleaning.enabled_steps', []);
        }

        // Filter and sort steps by priority
        $stepsToRun = $this->steps
            ->when(
                ! empty($enabledSteps),
                fn (Collection $steps) => $steps->filter(
                    fn (CleaningStepInterface $step) => in_array($step->getName(), $enabledSteps, true)
                )
            )
            ->sortBy(fn (CleaningStepInterface $step) => $step->getPriority());

        // Run each step
        foreach ($stepsToRun as $step) {
            $result = $step->clean($text);
            $text = $result['text'];

            if ($result['changes_made'] > 0) {
                $stepsApplied[] = $step->getName();
            }
        }

        return new CleaningResult(
            text: $text,
            stepsApplied: $stepsApplied,
            charactersBefore: $charactersBefore,
            charactersAfter: mb_strlen($text),
        );
    }

    /**
     * Run a single step by name.
     */
    public function runStep(string $stepName, string $text): CleaningResult
    {
        $step = $this->getStep($stepName);

        if ($step === null) {
            return new CleaningResult(
                text: $text,
                stepsApplied: [],
                charactersBefore: mb_strlen($text),
                charactersAfter: mb_strlen($text),
            );
        }

        $charactersBefore = mb_strlen($text);
        $result = $step->clean($text);

        return new CleaningResult(
            text: $result['text'],
            stepsApplied: $result['changes_made'] > 0 ? [$stepName] : [],
            charactersBefore: $charactersBefore,
            charactersAfter: mb_strlen($result['text']),
        );
    }

    /**
     * Get available step names.
     *
     * @return array<string>
     */
    public function getAvailableSteps(): array
    {
        return $this->steps
            ->map(fn (CleaningStepInterface $step) => $step->getName())
            ->values()
            ->toArray();
    }

    /**
     * Get step descriptions.
     *
     * @return array<string, string>
     */
    public function getStepDescriptions(): array
    {
        return $this->steps
            ->mapWithKeys(fn (CleaningStepInterface $step) => [
                $step->getName() => $step->getDescription(),
            ])
            ->toArray();
    }
}
