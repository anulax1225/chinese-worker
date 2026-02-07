<?php

namespace App\Services\WebFetch;

use App\DTOs\WebFetch\FetchedDocument;
use App\Exceptions\WebFetchException;
use DOMDocument;
use DOMElement;
use DOMNode;
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
     * Block-level elements that should have line breaks.
     *
     * @var array<string>
     */
    protected array $blockElements = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'article', 'section', 'main', 'aside',
        'blockquote', 'pre', 'figure', 'figcaption',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr',
        'address', 'header', 'footer', 'nav',
    ];

    public function __construct(
        protected int $maxTextLength = 50000,
        protected int $minContentLength = 100,
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

        $baseContentType = $this->parseContentType($contentType);

        $extracted = match (true) {
            str_contains($baseContentType, 'html') => $this->extractHtml($content, $url),
            str_contains($baseContentType, 'json') => $this->extractJson($content, $url),
            str_contains($baseContentType, 'xml') => $this->extractXml($content, $url),
            str_starts_with($baseContentType, 'text/') => $this->extractPlainText($content, $url),
            default => throw WebFetchException::unsupportedContentType($contentType),
        };

        return new FetchedDocument(
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
    }

    /**
     * Extract content from HTML.
     *
     * @return array{title: string, text: string}
     */
    protected function extractHtml(string $html, string $url): array
    {
        libxml_use_internal_errors(true);

        $doc = new DOMDocument;
        $doc->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
        );

        libxml_clear_errors();

        // Remove script, style, and other non-content elements
        $this->removeUnwantedElements($doc);

        // Extract title before we start walking the tree
        $title = $this->extractTitle($doc, $url);

        // Extract main text content using scoring algorithm
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
     * Remove unwanted elements from DOM (scripts, styles, etc).
     */
    protected function removeUnwantedElements(DOMDocument $doc): void
    {
        $toRemove = [];

        foreach ($this->elementsToRemove as $tagName) {
            $elements = $doc->getElementsByTagName($tagName);
            foreach ($elements as $element) {
                $toRemove[] = $element;
            }
        }

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
        $titleElements = $doc->getElementsByTagName('title');
        if ($titleElements->length > 0) {
            $title = trim($titleElements->item(0)->textContent);
            if (! empty($title)) {
                return $title;
            }
        }

        $h1Elements = $doc->getElementsByTagName('h1');
        if ($h1Elements->length > 0) {
            $title = trim($h1Elements->item(0)->textContent);
            if (! empty($title)) {
                return $title;
            }
        }

        $xpath = new DOMXPath($doc);
        $ogTitle = $xpath->query("//meta[@property='og:title']/@content");
        if ($ogTitle && $ogTitle->length > 0) {
            $title = trim($ogTitle->item(0)->nodeValue);
            if (! empty($title)) {
                return $title;
            }
        }

        return $this->extractHostFromUrl($url);
    }

    /**
     * Extract main text content from HTML document using scoring algorithm.
     */
    protected function extractMainText(DOMDocument $doc): string
    {
        $xpath = new DOMXPath($doc);

        // Step 1: Try semantic containers first
        $mainContent = $this->findSemanticContainer($xpath);
        if ($mainContent !== null) {
            return $this->walkDomTree($mainContent);
        }

        // Step 2: Score all candidate containers and pick the best
        $bestContainer = $this->findBestContentContainer($doc, $xpath);
        if ($bestContainer !== null) {
            return $this->walkDomTree($bestContainer);
        }

        // Step 3: Fallback to body
        $bodies = $doc->getElementsByTagName('body');
        if ($bodies->length > 0) {
            return $this->walkDomTree($bodies->item(0));
        }

        return $this->normalizeWhitespace($doc->textContent ?? '');
    }

    /**
     * Find semantic content container (<main>, <article>, role="main").
     */
    protected function findSemanticContainer(DOMXPath $xpath): ?DOMElement
    {
        $selectors = [
            '//main[not(ancestor::aside)]',
            "//*[@role='main']",
            '//article[not(ancestor::aside)]',
        ];

        foreach ($selectors as $selector) {
            $elements = $xpath->query($selector);
            if ($elements && $elements->length > 0) {
                $element = $elements->item(0);
                if ($element instanceof DOMElement) {
                    $textLength = strlen(trim($element->textContent ?? ''));
                    if ($textLength > $this->minContentLength) {
                        return $element;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Find the best content container by scoring candidates.
     */
    protected function findBestContentContainer(DOMDocument $doc, DOMXPath $xpath): ?DOMElement
    {
        $candidates = $this->getCandidateContainers($doc, $xpath);

        if (empty($candidates)) {
            return null;
        }

        $scores = [];
        foreach ($candidates as $element) {
            $score = $this->scoreContainer($element);
            if ($score > 0) {
                $scores[] = [
                    'element' => $element,
                    'score' => $score,
                ];
            }
        }

        if (empty($scores)) {
            return null;
        }

        usort($scores, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $scores[0]['element'];
    }

    /**
     * Get candidate container elements for scoring.
     *
     * @return array<DOMElement>
     */
    protected function getCandidateContainers(DOMDocument $doc, DOMXPath $xpath): array
    {
        $candidates = [];
        $seen = [];

        $patterns = [
            "//*[contains(@class, 'content')]",
            "//*[contains(@class, 'post')]",
            "//*[contains(@class, 'article')]",
            "//*[contains(@class, 'entry')]",
            "//*[contains(@class, 'text')]",
            "//*[contains(@class, 'body')]",
            "//*[contains(@id, 'content')]",
            "//*[contains(@id, 'main')]",
            "//*[contains(@id, 'post')]",
            "//*[contains(@id, 'article')]",
        ];

        foreach ($patterns as $pattern) {
            $elements = $xpath->query($pattern);
            if ($elements) {
                foreach ($elements as $element) {
                    if ($element instanceof DOMElement && ! $this->isHiddenElement($element)) {
                        $id = spl_object_id($element);
                        if (! isset($seen[$id])) {
                            $candidates[] = $element;
                            $seen[$id] = true;
                        }
                    }
                }
            }
        }

        // Also add divs with substantial text
        $divs = $doc->getElementsByTagName('div');
        foreach ($divs as $div) {
            if ($div instanceof DOMElement) {
                $id = spl_object_id($div);
                if (! isset($seen[$id])) {
                    $textLength = strlen(trim($div->textContent ?? ''));
                    if ($textLength > 200 && ! $this->isHiddenElement($div)) {
                        $candidates[] = $div;
                        $seen[$id] = true;
                    }
                }
            }
        }

        return $candidates;
    }

    /**
     * Score a container element for content quality.
     */
    protected function scoreContainer(DOMElement $element): float
    {
        $text = trim($element->textContent ?? '');
        $textLength = strlen($text);

        if ($textLength < 50) {
            return 0.0;
        }

        $textDensity = $this->calculateTextDensity($element);
        $linkDensity = $this->calculateLinkDensity($element);
        $paragraphCount = $this->countParagraphs($element);
        $semanticBonus = $this->getSemanticBonus($element);

        $score = 0.0;
        $score += $textDensity * 50;
        $score -= $linkDensity * 40;
        $score += min($paragraphCount, 20) * 8;
        $score += log10(max($textLength, 1)) * 10;
        $score += $semanticBonus;

        if ($textLength < 200) {
            $score *= 0.5;
        }

        if ($this->hasNavigationClassOrId($element)) {
            $score *= 0.3;
        }

        return max($score, 0.0);
    }

    /**
     * Calculate text density (text characters / HTML length).
     */
    protected function calculateTextDensity(DOMElement $element): float
    {
        $html = $element->ownerDocument?->saveHTML($element) ?? '';
        $htmlLength = strlen($html);

        if ($htmlLength === 0) {
            return 0.0;
        }

        $textLength = strlen(trim($element->textContent ?? ''));

        return $textLength / $htmlLength;
    }

    /**
     * Calculate link density (link text / total text).
     */
    protected function calculateLinkDensity(DOMElement $element): float
    {
        $totalText = strlen(trim($element->textContent ?? ''));

        if ($totalText === 0) {
            return 0.0;
        }

        $linkText = 0;
        $links = $element->getElementsByTagName('a');
        foreach ($links as $link) {
            $linkText += strlen(trim($link->textContent ?? ''));
        }

        return $linkText / $totalText;
    }

    /**
     * Count paragraph elements.
     */
    protected function countParagraphs(DOMElement $element): int
    {
        return $element->getElementsByTagName('p')->length;
    }

    /**
     * Get semantic bonus for meaningful HTML5 elements.
     */
    protected function getSemanticBonus(DOMElement $element): float
    {
        $tagName = strtolower($element->tagName);

        $bonuses = [
            'main' => 100.0,
            'article' => 80.0,
            'section' => 20.0,
        ];

        $bonus = $bonuses[$tagName] ?? 0.0;

        if ($element->getAttribute('role') === 'main') {
            $bonus += 90.0;
        }

        $id = strtolower($element->getAttribute('id'));
        if (in_array($id, ['content', 'main-content', 'article', 'post'], true)) {
            $bonus += 30.0;
        }

        return $bonus;
    }

    /**
     * Check if element has navigation-like class or ID.
     */
    protected function hasNavigationClassOrId(DOMElement $element): bool
    {
        $class = strtolower($element->getAttribute('class'));
        $id = strtolower($element->getAttribute('id'));

        $navPatterns = ['nav', 'menu', 'sidebar', 'footer', 'header', 'breadcrumb', 'pagination'];

        foreach ($navPatterns as $pattern) {
            if (str_contains($class, $pattern) || str_contains($id, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an element is hidden.
     */
    protected function isHiddenElement(DOMElement $element): bool
    {
        if ($element->hasAttribute('hidden')) {
            return true;
        }

        if ($element->getAttribute('aria-hidden') === 'true') {
            return true;
        }

        $style = $element->getAttribute('style');
        if (! empty($style)) {
            if (preg_match('/display\s*:\s*none/i', $style)) {
                return true;
            }
            if (preg_match('/visibility\s*:\s*hidden/i', $style)) {
                return true;
            }
        }

        if (strtolower($element->tagName) === 'input' && $element->getAttribute('type') === 'hidden') {
            return true;
        }

        return false;
    }

    /**
     * Walk DOM tree and extract structured text.
     */
    protected function walkDomTree(DOMNode $node): string
    {
        $output = '';

        foreach ($node->childNodes as $child) {
            $output .= $this->processNode($child);
        }

        return $this->normalizeWhitespace($output);
    }

    /**
     * Process a single DOM node and return its text representation.
     */
    protected function processNode(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent ?? '';
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        /** @var DOMElement $node */
        $tagName = strtolower($node->tagName);

        if ($this->isHiddenElement($node)) {
            return '';
        }

        // Skip navigation elements
        if (in_array($tagName, ['nav', 'aside', 'header', 'footer'], true)) {
            return '';
        }

        // Skip elements with sr-only or visually-hidden classes
        $class = strtolower($node->getAttribute('class'));
        if (str_contains($class, 'sr-only') || str_contains($class, 'visually-hidden') || str_contains($class, 'screen-reader')) {
            return '';
        }

        // Skip elements with skip in class/id (accessibility skip links)
        if (str_contains($class, 'skip') || str_contains(strtolower($node->getAttribute('id')), 'skip')) {
            return '';
        }

        // Skip anchor tags that are accessibility skip/jump links
        if ($tagName === 'a') {
            $linkText = strtolower(trim($node->textContent ?? ''));
            if (str_contains($linkText, 'skip') || str_contains($linkText, 'jump to')) {
                return '';
            }
        }

        switch ($tagName) {
            case 'br':
                return "\n";

            case 'hr':
                return "\n\n---\n\n";

            case 'li':
                $content = $this->walkDomTree($node);
                $parent = $node->parentNode;
                $prefix = ($parent && $parent->nodeName === 'ol') ? '1. ' : '- ';

                return $prefix.trim($content)."\n";

            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $content = trim($node->textContent ?? '');
                if (empty($content)) {
                    return '';
                }
                $level = (int) substr($tagName, 1);
                $prefix = str_repeat('#', $level).' ';

                return "\n\n".$prefix.$content."\n\n";

            case 'blockquote':
                $content = trim($this->walkDomTree($node));
                if (empty($content)) {
                    return '';
                }
                $lines = explode("\n", $content);
                $quoted = array_map(fn ($line) => '> '.$line, $lines);

                return "\n\n".implode("\n", $quoted)."\n\n";

            case 'pre':
                $content = $node->textContent ?? '';

                return "\n\n```\n".$content."\n```\n\n";

            case 'code':
                $content = trim($node->textContent ?? '');
                // Only inline code if not inside pre
                if ($node->parentNode && strtolower($node->parentNode->nodeName) === 'pre') {
                    return $content;
                }

                return '`'.$content.'`';

            case 'a':
                return $this->walkDomTree($node);

            case 'img':
                $alt = $node->getAttribute('alt');

                return $alt ? "[Image: {$alt}]" : '';

            case 'table':
                return $this->processTable($node);

            default:
                if (in_array($tagName, $this->blockElements, true)) {
                    $content = $this->walkDomTree($node);

                    return "\n".$content."\n";
                }

                return $this->walkDomTree($node);
        }
    }

    /**
     * Process table element into readable text.
     */
    protected function processTable(DOMElement $table): string
    {
        $output = "\n\n";

        $rows = $table->getElementsByTagName('tr');
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if ($cell->nodeType === XML_ELEMENT_NODE) {
                    $cellTagName = strtolower($cell->tagName);
                    if ($cellTagName === 'th' || $cellTagName === 'td') {
                        $cells[] = trim($cell->textContent ?? '');
                    }
                }
            }
            if (! empty($cells)) {
                $output .= implode(' | ', $cells)."\n";
            }
        }

        return $output."\n";
    }

    /**
     * Normalize whitespace in extracted text.
     */
    protected function normalizeWhitespace(string $text): string
    {
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n\s*\n+/', "\n\n", $text);

        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);

        return trim($text);
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
            minContentLength: config('webfetch.extraction.min_content_length', 100),
        );
    }
}
