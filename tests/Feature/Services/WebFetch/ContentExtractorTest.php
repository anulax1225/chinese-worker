<?php

use App\Services\WebFetch\ContentExtractor;

beforeEach(function () {
    $this->extractor = new ContentExtractor;
});

test('extracts text from HTML content', function () {
    $response = [
        'body' => '<html><head><title>Test Page</title></head><body><main><p>Hello World</p></main></body></html>',
        'content_type' => 'text/html',
        'status_code' => 200,
    ];

    $document = $this->extractor->extract($response, 'https://example.com', 0.1);

    expect($document->title)->toBe('Test Page');
    expect($document->text)->toContain('Hello World');
});

test('removes skip to content links from HTML', function () {
    $response = [
        'body' => '<html><head><title>Test Page</title></head><body>
            <a class="skip-link" href="#content">Skip to content</a>
            <a class="skip-to-main" href="#main">Skip to main content</a>
            <main id="content"><p>Actual content here</p></main>
        </body></html>',
        'content_type' => 'text/html',
        'status_code' => 200,
    ];

    $document = $this->extractor->extract($response, 'https://example.com', 0.1);

    expect($document->text)->not->toContain('Skip to content');
    expect($document->text)->not->toContain('Skip to main content');
    expect($document->text)->toContain('Actual content here');
});

test('removes skip links with various class patterns', function () {
    $response = [
        'body' => '<html><head><title>Test</title></head><body>
            <div class="skip-navigation">Skip Nav</div>
            <a id="skip-to-content" href="#">Skip Here</a>
            <main><p>Main content</p></main>
        </body></html>',
        'content_type' => 'text/html',
        'status_code' => 200,
    ];

    $document = $this->extractor->extract($response, 'https://example.com', 0.1);

    expect($document->text)->not->toContain('Skip Nav');
    expect($document->text)->not->toContain('Skip Here');
    expect($document->text)->toContain('Main content');
});

test('removes navigation elements', function () {
    $response = [
        'body' => '<html><head><title>Test</title></head><body>
            <nav>Navigation menu</nav>
            <header>Site header</header>
            <main><p>Page content</p></main>
            <footer>Site footer</footer>
        </body></html>',
        'content_type' => 'text/html',
        'status_code' => 200,
    ];

    $document = $this->extractor->extract($response, 'https://example.com', 0.1);

    expect($document->text)->not->toContain('Navigation menu');
    expect($document->text)->not->toContain('Site header');
    expect($document->text)->not->toContain('Site footer');
    expect($document->text)->toContain('Page content');
});

test('extracts JSON content', function () {
    $response = [
        'body' => '{"message": "Hello", "status": "ok"}',
        'content_type' => 'application/json',
        'status_code' => 200,
    ];

    $document = $this->extractor->extract($response, 'https://api.example.com/data', 0.1);

    expect($document->text)->toContain('"message": "Hello"');
    expect($document->text)->toContain('"status": "ok"');
    expect($document->contentType)->toBe('application/json');
});

test('extracts plain text content', function () {
    $response = [
        'body' => 'This is plain text content.',
        'content_type' => 'text/plain',
        'status_code' => 200,
    ];

    $document = $this->extractor->extract($response, 'https://example.com/file.txt', 0.1);

    expect($document->text)->toBe('This is plain text content.');
    expect($document->contentType)->toBe('text/plain');
});

test('extracts title from h1 when title tag is missing', function () {
    $response = [
        'body' => '<html><body><h1>Page Heading</h1><p>Content</p></body></html>',
        'content_type' => 'text/html',
        'status_code' => 200,
    ];

    $document = $this->extractor->extract($response, 'https://example.com', 0.1);

    expect($document->title)->toBe('Page Heading');
});

test('falls back to domain for title when no title elements exist', function () {
    $response = [
        'body' => '<html><body><p>Just content</p></body></html>',
        'content_type' => 'text/html',
        'status_code' => 200,
    ];

    $document = $this->extractor->extract($response, 'https://example.com/page', 0.1);

    expect($document->title)->toBe('example.com');
});

test('removes skip the content links without semantic containers', function () {
    $response = [
        'body' => '<!DOCTYPE html><html><head><title>Test</title></head><body>
            <a href="#x">Skip the content</a>
            <h1>Main Heading</h1>
            <p>Actual content here</p>
        </body></html>',
        'content_type' => 'text/html',
        'status_code' => 200,
    ];

    $document = $this->extractor->extract($response, 'https://example.com', 0.1);

    expect($document->text)->not->toContain('Skip the content');
    expect($document->text)->toContain('Actual content here');
});

test('removes screen reader only elements', function () {
    $response = [
        'body' => '<html><head><title>Test</title></head><body>
            <span class="sr-only">Screen reader text</span>
            <span class="visually-hidden">Hidden text</span>
            <main><p>Visible content</p></main>
        </body></html>',
        'content_type' => 'text/html',
        'status_code' => 200,
    ];

    $document = $this->extractor->extract($response, 'https://example.com', 0.1);

    expect($document->text)->not->toContain('Screen reader text');
    expect($document->text)->not->toContain('Hidden text');
    expect($document->text)->toContain('Visible content');
});

test('removes jump to navigation links', function () {
    $response = [
        'body' => '<!DOCTYPE html><html><head><title>Test</title></head><body>
            <a href="#main">Jump to main content</a>
            <h1>Page Title</h1>
            <p>Page content</p>
        </body></html>',
        'content_type' => 'text/html',
        'status_code' => 200,
    ];

    $document = $this->extractor->extract($response, 'https://example.com', 0.1);

    expect($document->text)->not->toContain('Jump to main content');
    expect($document->text)->toContain('Page content');
});
