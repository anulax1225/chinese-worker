<?php

use App\Services\Document\CleaningPipeline;
use App\Services\Document\CleaningSteps\FixBrokenLinesStep;
use App\Services\Document\CleaningSteps\NormalizeEncodingStep;
use App\Services\Document\CleaningSteps\NormalizeQuotesStep;
use App\Services\Document\CleaningSteps\NormalizeWhitespaceStep;
use App\Services\Document\CleaningSteps\RemoveBoilerplateStep;
use App\Services\Document\CleaningSteps\RemoveControlCharactersStep;
use App\Services\Document\CleaningSteps\RemoveHeadersFootersStep;

describe('CleaningPipeline', function () {
    beforeEach(function () {
        $this->pipeline = new CleaningPipeline;
    });

    test('runs registered steps and tracks changes', function () {
        $this->pipeline->register(new NormalizeWhitespaceStep); // priority 30
        $this->pipeline->register(new NormalizeEncodingStep);   // priority 10

        // Pass empty array to run all registered steps
        // Use text with whitespace issues so NormalizeWhitespaceStep makes changes
        $result = $this->pipeline->clean("test  text\r\n", []);

        // NormalizeWhitespaceStep should have made changes (collapsed spaces, normalized line endings)
        expect($result->stepsApplied)->toContain('normalize_whitespace');
        expect($result->text)->toBe('test text');
    });

    test('returns CleaningResult with character counts', function () {
        $this->pipeline->register(new NormalizeWhitespaceStep);

        $result = $this->pipeline->clean('hello   world', []);

        expect($result->charactersBefore)->toBe(13);
        expect($result->charactersAfter)->toBe(11);
        expect($result->charactersRemoved())->toBe(2);
    });

    test('filters steps by enabled list', function () {
        $this->pipeline->register(new NormalizeEncodingStep);
        $this->pipeline->register(new NormalizeWhitespaceStep);
        $this->pipeline->register(new NormalizeQuotesStep);

        // Use text with BOM so encoding step makes changes, and extra spaces so whitespace would too
        $textWithBom = "\xEF\xBB\xBFtest  text";
        $result = $this->pipeline->clean($textWithBom, ['normalize_encoding']);

        // Only encoding step ran (removed BOM), whitespace step was filtered out
        expect($result->stepsApplied)->toBe(['normalize_encoding']);
        expect($result->text)->toBe('test  text'); // Spaces preserved since whitespace step didn't run
    });

    test('returns unchanged text when no steps registered', function () {
        $text = 'unchanged text';
        $result = $this->pipeline->clean($text, []);

        expect($result->text)->toBe($text);
        expect($result->stepsApplied)->toBe([]);
    });

    test('getSteps returns registered steps', function () {
        $step = new NormalizeEncodingStep;
        $this->pipeline->register($step);

        expect($this->pipeline->getSteps())->toHaveCount(1);
        expect($this->pipeline->getSteps()[0])->toBe($step);
    });
});

describe('NormalizeEncodingStep', function () {
    beforeEach(function () {
        $this->step = new NormalizeEncodingStep;
    });

    test('removes UTF-8 BOM', function () {
        $textWithBom = "\xEF\xBB\xBFHello World";
        $result = $this->step->clean($textWithBom);

        expect($result['text'])->toBe('Hello World');
        expect($result['changes_made'])->toBeGreaterThan(0);
    });

    test('fixes common mojibake patterns', function () {
        $mojibake = "It's a test";
        $result = $this->step->clean($mojibake);

        expect($result['text'])->toContain("'");
    });

    test('has correct priority', function () {
        expect($this->step->getPriority())->toBe(10);
    });

    test('has correct name', function () {
        expect($this->step->getName())->toBe('normalize_encoding');
    });
});

describe('RemoveControlCharactersStep', function () {
    beforeEach(function () {
        $this->step = new RemoveControlCharactersStep;
    });

    test('removes null bytes', function () {
        $text = "Hello\x00World";
        $result = $this->step->clean($text);

        expect($result['text'])->toBe('HelloWorld');
    });

    test('preserves newlines and tabs', function () {
        $text = "Hello\n\tWorld";
        $result = $this->step->clean($text);

        expect($result['text'])->toBe("Hello\n\tWorld");
    });

    test('removes zero-width characters', function () {
        $text = "Hello\xE2\x80\x8BWorld"; // Zero-width space
        $result = $this->step->clean($text);

        expect($result['text'])->toBe('HelloWorld');
    });

    test('has correct priority', function () {
        expect($this->step->getPriority())->toBe(20);
    });
});

describe('NormalizeWhitespaceStep', function () {
    beforeEach(function () {
        $this->step = new NormalizeWhitespaceStep;
    });

    test('normalizes Windows line endings', function () {
        $text = "Line1\r\nLine2";
        $result = $this->step->clean($text);

        expect($result['text'])->toBe("Line1\nLine2");
    });

    test('collapses multiple spaces', function () {
        $text = 'Hello    World';
        $result = $this->step->clean($text);

        expect($result['text'])->toBe('Hello World');
    });

    test('removes trailing whitespace from lines', function () {
        $text = "Hello World   \nNext line";
        $result = $this->step->clean($text);

        expect($result['text'])->toBe("Hello World\nNext line");
    });

    test('limits consecutive blank lines', function () {
        $text = "Para1\n\n\n\n\nPara2";
        $result = $this->step->clean($text);

        expect($result['text'])->toBe("Para1\n\nPara2");
    });

    test('has correct priority', function () {
        expect($this->step->getPriority())->toBe(30);
    });
});

describe('FixBrokenLinesStep', function () {
    beforeEach(function () {
        $this->step = new FixBrokenLinesStep;
    });

    test('rejoins hyphenated line breaks', function () {
        $text = "This is a long docu-\nment that was broken.";
        $result = $this->step->clean($text);

        expect($result['text'])->toBe('This is a long document that was broken.');
    });

    test('preserves paragraph breaks', function () {
        $text = "First paragraph.\n\nSecond paragraph.";
        $result = $this->step->clean($text);

        expect($result['text'])->toBe("First paragraph.\n\nSecond paragraph.");
    });

    test('has correct priority', function () {
        expect($this->step->getPriority())->toBe(40);
    });
});

describe('RemoveHeadersFootersStep', function () {
    beforeEach(function () {
        $this->step = new RemoveHeadersFootersStep;
    });

    test('removes page number patterns', function () {
        $text = "Content here\nPage 1 of 10\nMore content";
        $result = $this->step->clean($text);

        expect($result['text'])->not->toContain('Page 1 of 10');
    });

    test('removes standalone page numbers', function () {
        $text = "Content\n- 5 -\nMore content";
        $result = $this->step->clean($text);

        expect($result['text'])->not->toContain('- 5 -');
    });

    test('has correct priority', function () {
        expect($this->step->getPriority())->toBe(50);
    });
});

describe('RemoveBoilerplateStep', function () {
    beforeEach(function () {
        $this->step = new RemoveBoilerplateStep;
    });

    test('removes copyright notices', function () {
        $text = "Content\nCopyright 2024 Company Inc.\nMore content";
        $result = $this->step->clean($text);

        expect($result['text'])->not->toContain('Copyright 2024');
    });

    test('removes all rights reserved', function () {
        $text = "Content\nAll rights reserved.\nMore content";
        $result = $this->step->clean($text);

        expect($result['text'])->not->toContain('All rights reserved');
    });

    test('has correct priority', function () {
        expect($this->step->getPriority())->toBe(60);
    });
});

describe('NormalizeQuotesStep', function () {
    beforeEach(function () {
        $this->step = new NormalizeQuotesStep;
    });

    test('converts smart single quotes to straight quotes', function () {
        $text = "It\u{2019}s a \u{2018}test\u{2019}";
        $result = $this->step->clean($text);

        expect($result['text'])->toBe("It's a 'test'");
    });

    test('converts smart double quotes to straight quotes', function () {
        $text = "\u{201C}Hello World\u{201D}"; // Left and right double quotes
        $result = $this->step->clean($text);

        expect($result['text'])->toBe('"Hello World"');
    });

    test('normalizes em dashes', function () {
        $text = "One\u{2014}Two"; // Em dash
        $result = $this->step->clean($text);

        expect($result['text'])->toBe('One--Two');
    });

    test('normalizes ellipsis', function () {
        $text = "Wait\u{2026}"; // Horizontal ellipsis
        $result = $this->step->clean($text);

        expect($result['text'])->toBe('Wait...');
    });

    test('has correct priority', function () {
        expect($this->step->getPriority())->toBe(70);
    });
});
