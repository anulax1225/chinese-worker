<?php

use App\Services\Document\Extractors\TextractExtractor;

test('returns correct extractor name', function () {
    $extractor = new TextractExtractor;

    expect($extractor->getName())->toBe('textract');
});

test('supports PDF mime type', function () {
    $extractor = new TextractExtractor;

    expect($extractor->supports('application/pdf'))->toBeTrue();
});

test('supports DOCX mime type', function () {
    $extractor = new TextractExtractor;

    expect($extractor->supports(
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ))->toBeTrue();
});

test('supports image mime types for OCR', function () {
    $extractor = new TextractExtractor;

    expect($extractor->supports('image/jpeg'))->toBeTrue();
    expect($extractor->supports('image/png'))->toBeTrue();
    expect($extractor->supports('image/gif'))->toBeTrue();
});

test('does not support unsupported mime types', function () {
    $extractor = new TextractExtractor;

    expect($extractor->supports('video/mp4'))->toBeFalse();
    expect($extractor->supports('audio/mpeg'))->toBeFalse();
});

test('returns supported mime types array', function () {
    $extractor = new TextractExtractor;

    $types = $extractor->getSupportedMimeTypes();

    expect($types)->toBeArray();
    expect($types)->toContain('application/pdf');
    expect($types)->toContain('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($types)->toContain('image/jpeg');
});

test('returns failure for missing file', function () {
    $extractor = new TextractExtractor;

    $result = $extractor->extract('/nonexistent/file.pdf');

    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('File not found');
});
