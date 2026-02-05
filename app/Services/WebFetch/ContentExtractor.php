<?php

namespace App\Services\WebFetch;

use App\DTOs\WebFetch\FetchedDocument;
use App\Exceptions\WebFetchException;
use DOMDocument;
use DOMXPath;

class ContentExtractor
{
    /**
     * Elements to remove from HTML before text extraction.
     *
     * @var array<string>
     */
    protected array $elementsToRemove = [
        'script',
        'style',
        'noscript',
        'iframe',
        'svg',
        'canvas',
        'video',
        'audio',
        'form',
        'input',
        'button',
        'select',
        'textarea',
    ];

    /**
     * Navigation elements to remove.
     *
     * @var array<string>
     */
    protected array $navigationElements = [
        'nav',
        'header',
        'footer',
        'aside',
    ];

    public function __construct(
        protected int $maxTextLength = 50000,
        protected bool $removeScripts = true,
        protected bool $removeStyles = true,
        protected bool $removeNavigation = true,
    ) {}

    /**
     * Extract readable content from raw response.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function extract(array $responseData, string $url, float $fetchTime): FetchedDocument
    {
        $content = $responseData['body'] ?? '';
        $contentType = $responseData['content_type'] ?? 'text/html';
        $statusCode = $responseData['status_code'] ?? 200;
        $contentLength = $responseData['content_length'] ?? strlen($content);

        if (empty($content)) {
            throw WebFetchException::emptyResponse();
        }

        // Determine extraction method based on content type
        $baseContentType = $this->parseContentType($contentType);

        $extracted = match (true) {
            str_contains($baseContentType, 'html') => $this->extractHtml($content, $url),
            str_contains($baseContentType, 'json') => $this->extractJson($content, $url),
            str_contains($baseContentType, 'xml') => $this->extractXml($content, $url),
            str_starts_with($baseContentType, 'text/') => $this->extractPlainText($content, $url),
            default => throw WebFetchException::unsupportedContentType($contentType),
        };

        $document = new FetchedDocument(
            url: $url,
            title: $extracted['title'],
            text: $this->truncateText($extracted['text']),
            contentType: $baseContentType,
            fetchTime: $fetchTime,
            fromCache: false,
            metadata: [
                'status_code' => $statusCode,
                'content_length' => $contentLength,
                'original_content_type' => $contentType,
            ],
        );

        return $document;
    }

    /**
     * Extract content from HTML.
     *
     * @return array{title: string, text: string}
     */
    protected function extractHtml(string $html, string $url): array
    {
        // Suppress DOM parsing errors
        libxml_use_internal_errors(true);

        $doc = new DOMDocument;
        $doc->loadHTML(
            mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();

        // Remove unwanted elements
        $this->removeUnwantedElements($doc);

        // Extract title
        $title = $this->extractTitle($doc, $url);

        // Extract main text content
        $text = $this->extractMainText($doc);

        return [
            'title' => $title,
            'text' => $text,
        ];
    }

    /**
     * Extract content from JSON.
     *
     * @return array{title: string, text: string}
     */
    protected function extractJson(string $json, string $url): array
    {
        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw WebFetchException::extractionFailed('Invalid JSON: '.json_last_error_msg());
        }

        // Pretty print for readability
        $text = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'title' => $this->extractHostFromUrl($url).' - JSON Response',
            'text' => $text,
        ];
    }

    /**
     * Extract content from XML.
     *
     * @return array{title: string, text: string}
     */
    protected function extractXml(string $xml, string $url): array
    {
        libxml_use_internal_errors(true);

        $doc = new DOMDocument;
        $doc->loadXML($xml);

        $errors = libxml_get_errors();
        libxml_clear_errors();

        if (! empty($errors)) {
            throw WebFetchException::extractionFailed('Invalid XML');
        }

        // Get formatted XML
        $doc->formatOutput = true;
        $text = $doc->saveXML();

        return [
            'title' => $this->extractHostFromUrl($url).' - XML Document',
            'text' => $text,
        ];
    }

    /**
     * Extract content from plain text.
     *
     * @return array{title: string, text: string}
     */
    protected function extractPlainText(string $text, string $url): array
    {
        return [
            'title' => $this->extractHostFromUrl($url),
            'text' => $text,
        ];
    }

    /**
     * Remove unwanted elements from DOM.
     */
    protected function removeUnwantedElements(DOMDocument $doc): void
    {
        $xpath = new DOMXPath($doc);

        // Collect elements to remove
        $toRemove = [];

        foreach ($this->elementsToRemove as $tagName) {
            $elements = $doc->getElementsByTagName($tagName);
            foreach ($elements as $element) {
                $toRemove[] = $element;
            }
        }

        // Remove navigation elements if configured
        if ($this->removeNavigation) {
            foreach ($this->navigationElements as $tagName) {
                $elements = $doc->getElementsByTagName($tagName);
                foreach ($elements as $element) {
                    $toRemove[] = $element;
                }
            }

            // Also remove common navigation classes/IDs
            $navPatterns = [
                "//*[contains(@class, 'nav')]",
                "//*[contains(@class, 'menu')]",
                "//*[contains(@class, 'sidebar')]",
                "//*[contains(@class, 'footer')]",
                "//*[contains(@class, 'header')]",
                "//*[contains(@id, 'nav')]",
                "//*[contains(@id, 'menu')]",
                "//*[contains(@id, 'sidebar')]",
                "//*[contains(@id, 'footer')]",
                "//*[contains(@id, 'header')]",
            ];

            foreach ($navPatterns as $pattern) {
                $elements = $xpath->query($pattern);
                if ($elements) {
                    foreach ($elements as $element) {
                        $toRemove[] = $element;
                    }
                }
            }
        }

        // Remove collected elements
        foreach ($toRemove as $element) {
            if ($element->parentNode) {
                $element->parentNode->removeChild($element);
            }
        }
    }

    /**
     * Extract title from HTML document.
     */
    protected function extractTitle(DOMDocument $doc, string $url): string
    {
        // Try <title> tag first
        $titleElements = $doc->getElementsByTagName('title');
        if ($titleElements->length > 0) {
            $title = trim($titleElements->item(0)->textContent);
            if (! empty($title)) {
                return $title;
            }
        }

        // Try <h1> tag
        $h1Elements = $doc->getElementsByTagName('h1');
        if ($h1Elements->length > 0) {
            $title = trim($h1Elements->item(0)->textContent);
            if (! empty($title)) {
                return $title;
            }
        }

        // Try og:title meta tag
        $xpath = new DOMXPath($doc);
        $ogTitle = $xpath->query("//meta[@property='og:title']/@content");
        if ($ogTitle && $ogTitle->length > 0) {
            $title = trim($ogTitle->item(0)->nodeValue);
            if (! empty($title)) {
                return $title;
            }
        }

        // Fallback to URL host
        return $this->extractHostFromUrl($url);
    }

    /**
     * Extract main text content from HTML document.
     */
    protected function extractMainText(DOMDocument $doc): string
    {
        $xpath = new DOMXPath($doc);

        // Try to find main content containers in order of preference
        $contentSelectors = [
            '//main',
            '//article',
            "//*[contains(@class, 'content')]",
            "//*[contains(@class, 'post')]",
            "//*[contains(@class, 'article')]",
            "//*[contains(@id, 'content')]",
            "//*[contains(@id, 'main')]",
            '//body',
        ];

        foreach ($contentSelectors as $selector) {
            $elements = $xpath->query($selector);
            if ($elements && $elements->length > 0) {
                $text = $this->getTextContent($elements->item(0));
                if (! empty(trim($text))) {
                    return $this->normalizeWhitespace($text);
                }
            }
        }

        // Fallback: get all text from document
        $text = $doc->textContent;

        return $this->normalizeWhitespace($text);
    }

    /**
     * Get text content from a DOM node.
     */
    protected function getTextContent(\DOMNode $node): string
    {
        return $node->textContent;
    }

    /**
     * Normalize whitespace in extracted text.
     */
    protected function normalizeWhitespace(string $text): string
    {
        // Replace multiple whitespace with single space
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // Replace multiple newlines with double newline (paragraph break)
        $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);

        // Trim each line
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        // Remove empty lines at start/end
        $text = trim($text);

        return $text;
    }

    /**
     * Truncate text to max length.
     */
    protected function truncateText(string $text): string
    {
        if (strlen($text) <= $this->maxTextLength) {
            return $text;
        }

        $text = mb_substr($text, 0, $this->maxTextLength);

        // Cut at last paragraph break or word boundary
        $lastParagraph = mb_strrpos($text, "\n\n");
        if ($lastParagraph !== false && $lastParagraph > $this->maxTextLength * 0.8) {
            $text = mb_substr($text, 0, $lastParagraph);
        } else {
            $lastSpace = mb_strrpos($text, ' ');
            if ($lastSpace !== false && $lastSpace > $this->maxTextLength * 0.9) {
                $text = mb_substr($text, 0, $lastSpace);
            }
        }

        return $text.'... [content truncated]';
    }

    /**
     * Parse content type header to get base type.
     */
    protected function parseContentType(string $contentType): string
    {
        // Extract base content type (before semicolon)
        $parts = explode(';', $contentType);

        return strtolower(trim($parts[0]));
    }

    /**
     * Extract host from URL for use as fallback title.
     */
    protected function extractHostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host ?: $url;
    }

    /**
     * Create instance from config.
     */
    public static function fromConfig(): self
    {
        return new self(
            maxTextLength: config('webfetch.extraction.max_text_length', 50000),
            removeScripts: config('webfetch.extraction.remove_scripts', true),
            removeStyles: config('webfetch.extraction.remove_styles', true),
            removeNavigation: config('webfetch.extraction.remove_navigation', true),
        );
    }
}
