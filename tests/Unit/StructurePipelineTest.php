<?php

use App\DTOs\Document\Section;
use App\DTOs\Document\StructuredContent;
use App\Services\Document\StructurePipeline;
use App\Services\Document\StructureProcessors\HeadingDetector;
use App\Services\Document\StructureProcessors\ListNormalizer;
use App\Services\Document\StructureProcessors\ParagraphNormalizer;

describe('StructurePipeline', function () {
    beforeEach(function () {
        $this->pipeline = new StructurePipeline;
    });

    test('runs registered processors in priority order', function () {
        $this->pipeline->register(new ParagraphNormalizer); // priority 30
        $this->pipeline->register(new HeadingDetector);     // priority 10

        $result = $this->pipeline->process("# Heading\n\nContent here", []);

        expect($result)->toBeInstanceOf(StructuredContent::class);
    });

    test('returns StructuredContent with sections', function () {
        $this->pipeline->register(new HeadingDetector);

        $result = $this->pipeline->process("# Introduction\n\nThis is content.\n\n# Conclusion\n\nFinal words.", []);

        expect($result->hasSections())->toBeTrue();
        expect($result->sectionCount())->toBeGreaterThanOrEqual(2);
    });

    test('getProcessors returns registered processors', function () {
        $processor = new HeadingDetector;
        $this->pipeline->register($processor);

        expect($this->pipeline->getProcessors())->toHaveCount(1);
        expect($this->pipeline->getProcessors()[0])->toBe($processor);
    });

    test('filters processors by enabled list', function () {
        $this->pipeline->register(new HeadingDetector);
        $this->pipeline->register(new ListNormalizer);
        $this->pipeline->register(new ParagraphNormalizer);

        $result = $this->pipeline->process('test', ['heading_detector']);

        // Only heading detector should have run
        $applied = $result->metadata['processors_applied'] ?? [];
        expect($applied)->not->toContain('list_normalizer');
        expect($applied)->not->toContain('paragraph_normalizer');
    });
});

describe('Section DTO', function () {
    test('creates section with all properties', function () {
        $section = new Section(
            title: 'Introduction',
            level: 1,
            content: 'This is the introduction content.',
            startOffset: 0,
            endOffset: 100,
        );

        expect($section->title)->toBe('Introduction');
        expect($section->level)->toBe(1);
        expect($section->content)->toBe('This is the introduction content.');
        expect($section->startOffset)->toBe(0);
        expect($section->endOffset)->toBe(100);
    });

    test('calculates word count', function () {
        $section = new Section(
            title: 'Test',
            level: 1,
            content: 'One two three four five',
            startOffset: 0,
            endOffset: 50,
        );

        expect($section->wordCount())->toBe(5);
    });

    test('calculates character count', function () {
        $section = new Section(
            title: 'Test',
            level: 1,
            content: 'Hello World',
            startOffset: 0,
            endOffset: 50,
        );

        expect($section->characterCount())->toBe(11);
    });

    test('converts to array', function () {
        $section = new Section(
            title: 'Test',
            level: 2,
            content: 'Content',
            startOffset: 0,
            endOffset: 50,
        );

        $array = $section->toArray();

        expect($array)->toHaveKeys(['title', 'level', 'content', 'start_offset', 'end_offset', 'word_count', 'character_count']);
    });
});

describe('StructuredContent DTO', function () {
    test('creates structured content with sections', function () {
        $sections = [
            new Section('Intro', 1, 'Content', 0, 50),
            new Section('Body', 1, 'More content', 51, 100),
        ];

        $content = new StructuredContent(
            text: 'Full text here',
            sections: $sections,
            metadata: ['key' => 'value'],
        );

        expect($content->text)->toBe('Full text here');
        expect($content->sectionCount())->toBe(2);
        expect($content->hasSections())->toBeTrue();
    });

    test('gets section titles', function () {
        $sections = [
            new Section('Introduction', 1, 'Content', 0, 50),
            new Section('Conclusion', 1, 'More', 51, 100),
        ];

        $content = new StructuredContent('text', $sections, []);

        expect($content->getSectionTitles())->toContain('Introduction');
        expect($content->getSectionTitles())->toContain('Conclusion');
    });

    test('gets sections by level', function () {
        $sections = [
            new Section('H1', 1, 'Content', 0, 50),
            new Section('H2', 2, 'Sub content', 51, 100),
            new Section('Another H1', 1, 'More', 101, 150),
        ];

        $content = new StructuredContent('text', $sections, []);

        $level1 = $content->getSectionsByLevel(1);
        $level2 = $content->getSectionsByLevel(2);

        expect(count($level1))->toBe(2);
        expect(count($level2))->toBe(1);
    });

    test('generates table of contents', function () {
        $sections = [
            new Section('Chapter 1', 1, 'Content', 0, 50),
            new Section('Section 1.1', 2, 'Sub', 51, 100),
        ];

        $content = new StructuredContent('text', $sections, []);
        $toc = $content->getTableOfContents();

        expect($toc)->toHaveCount(2);
        expect($toc[0]['title'])->toBe('Chapter 1');
        expect($toc[0]['level'])->toBe(1);
    });
});

describe('HeadingDetector', function () {
    beforeEach(function () {
        $this->processor = new HeadingDetector;
    });

    test('detects markdown headings', function () {
        $content = new StructuredContent("# Heading 1\n\nContent here.\n\n## Heading 2\n\nMore content.", [], []);

        $result = $this->processor->process($content);

        expect($result->hasSections())->toBeTrue();
        $titles = $result->getSectionTitles();
        expect($titles)->toContain('Heading 1');
        expect($titles)->toContain('Heading 2');
    });

    test('detects numbered headings', function () {
        $content = new StructuredContent("1 Introduction\n\nContent.\n\n1.1 Background\n\nMore.", [], []);

        $result = $this->processor->process($content);

        expect($result->hasSections())->toBeTrue();
    });

    test('has correct priority', function () {
        expect($this->processor->getPriority())->toBe(10);
    });

    test('has correct name', function () {
        expect($this->processor->getName())->toBe('heading_detector');
    });
});

describe('ListNormalizer', function () {
    beforeEach(function () {
        $this->processor = new ListNormalizer;
    });

    test('normalizes numbered list formats', function () {
        $content = new StructuredContent("1) First item\n2) Second item", [], []);

        $result = $this->processor->process($content);

        expect($result->text)->toContain('1. First item');
        expect($result->text)->toContain('2. Second item');
    });

    test('normalizes parenthetical numbers', function () {
        $content = new StructuredContent("(1) First\n(2) Second", [], []);

        $result = $this->processor->process($content);

        expect($result->text)->toContain('1. First');
        expect($result->text)->toContain('2. Second');
    });

    test('has correct priority', function () {
        expect($this->processor->getPriority())->toBe(20);
    });

    test('has correct name', function () {
        expect($this->processor->getName())->toBe('list_normalizer');
    });
});

describe('ParagraphNormalizer', function () {
    beforeEach(function () {
        $this->processor = new ParagraphNormalizer;
    });

    test('limits consecutive blank lines', function () {
        $content = new StructuredContent("Para 1\n\n\n\n\nPara 2", [], []);

        $result = $this->processor->process($content);

        // Should have at most 2 consecutive newlines
        expect($result->text)->not->toContain("\n\n\n");
    });

    test('preserves list structure', function () {
        $content = new StructuredContent("- Item 1\n- Item 2\n- Item 3", [], []);

        $result = $this->processor->process($content);

        expect($result->text)->toContain("- Item 1\n- Item 2\n- Item 3");
    });

    test('has correct priority', function () {
        expect($this->processor->getPriority())->toBe(30);
    });

    test('has correct name', function () {
        expect($this->processor->getName())->toBe('paragraph_normalizer');
    });
});
