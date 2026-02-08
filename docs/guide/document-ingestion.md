# Document Ingestion

Chinese Worker includes a document ingestion pipeline that processes various file formats into clean, chunked text ready for AI consumption.

## Overview

The document ingestion pipeline transforms uploaded files into structured, token-bounded chunks that can be efficiently processed by AI models.

```
┌──────────────┐    ┌──────────────┐    ┌──────────────┐    ┌──────────────┐
│  Extraction  │ → │   Cleaning   │ → │Normalization │ → │   Chunking   │
│              │    │              │    │              │    │              │
│ Raw text from│    │ Remove junk, │    │ Detect       │    │ Split into   │
│ source file  │    │ fix encoding │    │ structure    │    │ token chunks │
└──────────────┘    └──────────────┘    └──────────────┘    └──────────────┘
```

### Input Methods

| Method | Description |
|--------|-------------|
| **File Upload** | Upload files directly via web UI or API |
| **URL Fetch** | Provide a URL to download and process |
| **Text Paste** | Paste text content directly |

## Supported File Types

| Category | Extensions | MIME Types |
|----------|------------|------------|
| **Plain Text** | .txt, .md, .csv, .json, .xml | text/plain, text/markdown, text/csv, application/json |
| **Documents** | .pdf, .docx, .doc, .rtf, .odt | application/pdf, application/msword, application/vnd.openxmlformats-officedocument.wordprocessingml.document |
| **Spreadsheets** | .xlsx, .xls, .ods | application/vnd.ms-excel, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet |
| **Presentations** | .pptx | application/vnd.openxmlformats-officedocument.presentationml.presentation |
| **Images (OCR)** | .jpg, .png, .gif | image/jpeg, image/png, image/gif |
| **Web** | .html | text/html, application/xhtml+xml |

### Extractors

Two extractors handle different file types:

- **PlainTextExtractor** - Handles text/* MIME types with encoding detection
- **TextractExtractor** - Handles documents, spreadsheets, PDFs, and images via OCR

## Processing Pipeline

### 1. Extraction

Extracts raw text from the source file based on its MIME type.

**Features:**
- Automatic encoding detection for text files
- PDF text extraction via pdftotext
- OCR for images (Tesseract with English + Chinese support)
- Office document parsing (DOCX, XLSX, PPTX)

### 2. Cleaning

Seven cleaning steps process the extracted text:

| Step | Description |
|------|-------------|
| `normalize_encoding` | Convert to UTF-8, remove BOM, fix mojibake |
| `remove_control_characters` | Strip control chars, preserve meaningful whitespace |
| `normalize_whitespace` | Collapse multiple spaces/newlines |
| `fix_broken_lines` | Rejoin hyphenated words, fix OCR artifacts |
| `remove_headers_footers` | Remove repeated page headers/footers |
| `remove_boilerplate` | Strip copyright notices, page numbers |
| `normalize_quotes` | Normalize curly quotes to straight quotes |

### 3. Normalization

Structure detection identifies document organization:

| Processor | Detection |
|-----------|-----------|
| **HeadingDetector** | Markdown headings, numbered sections, all-caps titles |
| **ListNormalizer** | Bullet and numbered lists with hierarchy |
| **ParagraphNormalizer** | Paragraph boundaries and spacing |

### 4. Chunking

Splits content into token-bounded segments:

- **Max Tokens** - Default 1000 tokens per chunk
- **Overlap** - Default 100 token overlap between chunks
- **Section Respect** - Prefers breaking at section boundaries
- **Token Estimation** - Heuristic (chars/4) or tiktoken

## Configuration

All settings are in `config/document.php`:

### Extraction Settings

```php
'extraction' => [
    'max_file_size' => env('DOCUMENT_MAX_SIZE', 50 * 1024 * 1024), // 50MB
    'timeout' => env('DOCUMENT_EXTRACTION_TIMEOUT', 60),
    'pdf' => [
        'driver' => env('PDF_EXTRACTOR_DRIVER', 'pdfparser'),
        'pdftotext_path' => env('PDFTOTEXT_PATH', '/usr/bin/pdftotext'),
    ],
],
```

### Cleaning Settings

```php
'cleaning' => [
    'enabled_steps' => [
        'normalize_encoding',
        'normalize_whitespace',
        'remove_control_characters',
        'fix_broken_lines',
        'remove_headers_footers',
        'remove_boilerplate',
        'normalize_quotes',
    ],
    'boilerplate_patterns' => [
        '/^Copyright \d{4}.*$/m',
        '/^All rights reserved\.?$/m',
        '/^Page \d+ of \d+$/m',
        '/^Confidential.*$/mi',
    ],
],
```

### Chunking Settings

```php
'chunking' => [
    'default_max_tokens' => env('CHUNK_MAX_TOKENS', 1000),
    'default_overlap_tokens' => env('CHUNK_OVERLAP_TOKENS', 100),
    'min_chunk_tokens' => 50,
    'respect_sections' => true,
    'token_estimation' => env('TOKEN_ESTIMATION_METHOD', 'heuristic'),
],
```

### Processing Settings

```php
'processing' => [
    'job_timeout' => env('DOCUMENT_JOB_TIMEOUT', 300), // 5 minutes
    'job_tries' => 1, // No retry to avoid duplicate processing
    'store_all_stages' => env('DOCUMENT_STORE_ALL_STAGES', true),
],
```

## Docker Requirements

The Sail container includes required system dependencies:

- **poppler-utils** - PDF text extraction (pdftotext)
- **tesseract-ocr** - OCR engine
- **tesseract-ocr-eng** - English language pack
- **tesseract-ocr-chi-sim** - Simplified Chinese language pack

These are pre-configured in `.sail/8.5/Dockerfile`.

### Local Development (Arch Linux)

```bash
# PDF extraction
sudo pacman -S poppler

# OCR for images
sudo pacman -S tesseract tesseract-data-eng tesseract-data-chi_sim
```

### Rebuild Docker

After modifying the Dockerfile:

```bash
./vendor/bin/sail build --no-cache
./vendor/bin/sail up -d
```

## API Endpoints

All endpoints require authentication via Sanctum.

### List Documents

```http
GET /api/v1/documents
```

**Query Parameters:**
- `page` - Page number
- `per_page` - Items per page (default 15)
- `status` - Filter by status (pending, ready, failed)
- `search` - Search by title

### Create Document

```http
POST /api/v1/documents
```

**Body (file upload):**
```json
{
    "source_type": "upload",
    "file": "<binary>",
    "title": "Optional Title"
}
```

**Body (URL):**
```json
{
    "source_type": "url",
    "url": "https://example.com/document.pdf",
    "title": "Optional Title"
}
```

**Body (text paste):**
```json
{
    "source_type": "paste",
    "text": "Content to process...",
    "title": "Optional Title"
}
```

### Show Document

```http
GET /api/v1/documents/{id}
```

### Get Processing Stages

```http
GET /api/v1/documents/{id}/stages
```

Returns all processing stages with content at each step.

### Get Chunks

```http
GET /api/v1/documents/{id}/chunks
```

**Query Parameters:**
- `page` - Page number
- `per_page` - Items per page (default 50)

### Preview Document

```http
GET /api/v1/documents/{id}/preview
```

Returns original vs cleaned content comparison with sample chunks.

### Reprocess Document

```http
POST /api/v1/documents/{id}/reprocess
```

Re-runs the entire pipeline on an existing document.

### Delete Document

```http
DELETE /api/v1/documents/{id}
```

### Get Supported Types

```http
GET /api/v1/documents/supported-types
```

Returns list of supported MIME types.

## Web UI

### Document List (`/documents`)

- Grid view of all documents with status badges
- Filter by status (All, Pending, Ready, Failed)
- Search by title
- Infinite scroll pagination

### Create Document (`/documents/create`)

- Drag-and-drop file upload
- URL input with fetch
- Text paste area
- Displays supported file types

### Document Detail (`/documents/{id}`)

- Document metadata (title, status, size, timestamps)
- Processing stages with content preview
- Chunk count and total tokens
- Reprocess and delete actions

## Document Status Flow

```
Pending → Extracting → Cleaning → Normalizing → Chunking → Ready
                                                          ↓
                                                        Failed
```

| Status | Description |
|--------|-------------|
| `pending` | Queued for processing |
| `extracting` | Extracting text from source |
| `cleaning` | Running cleaning pipeline |
| `normalizing` | Detecting document structure |
| `chunking` | Splitting into chunks |
| `ready` | Processing complete |
| `failed` | Processing failed (see error_message) |

## Extensibility

### Custom Extractor

Create a new extractor for additional file types:

```php
<?php

namespace App\Services\Document\Extractors;

use App\Contracts\TextExtractorInterface;
use App\DTOs\Document\ExtractionResult;

class CustomExtractor implements TextExtractorInterface
{
    public function getName(): string
    {
        return 'custom';
    }

    public function getSupportedMimeTypes(): array
    {
        return ['application/x-custom'];
    }

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, $this->getSupportedMimeTypes(), true);
    }

    public function extract(string $filePath, array $options = []): ExtractionResult
    {
        // Your extraction logic
        $text = file_get_contents($filePath);

        return ExtractionResult::success(
            text: $text,
            metadata: ['extractor' => 'custom']
        );
    }
}
```

Register in `DocumentServiceProvider`:

```php
$registry->register(new CustomExtractor);
```

### Custom Cleaning Step

```php
<?php

namespace App\Services\Document\CleaningSteps;

use App\Contracts\CleaningStepInterface;

class CustomCleaningStep implements CleaningStepInterface
{
    public function getName(): string
    {
        return 'custom_clean';
    }

    public function getDescription(): string
    {
        return 'Custom cleaning operation';
    }

    public function getPriority(): int
    {
        return 50; // Lower runs first
    }

    public function clean(string $text): array
    {
        $cleaned = str_replace('bad', 'good', $text);

        return [
            'text' => $cleaned,
            'changes_made' => ['Replaced bad with good'],
        ];
    }
}
```

## Troubleshooting

### Document Stuck in Processing

Check the queue worker is running:

```bash
./vendor/bin/sail artisan queue:work
```

Or view failed jobs:

```bash
./vendor/bin/sail artisan queue:failed
```

### Extraction Failed

Verify system dependencies are installed:

```bash
# Check pdftotext
./vendor/bin/sail exec laravel.test which pdftotext

# Check tesseract
./vendor/bin/sail exec laravel.test tesseract --version
```

### OCR Not Working

Ensure language packs are installed:

```bash
./vendor/bin/sail exec laravel.test tesseract --list-langs
```

Should show `eng` and `chi_sim`.

### Verify Pipeline

Test via Tinker:

```bash
./vendor/bin/sail artisan tinker

>>> $service = app(\App\Services\Document\DocumentIngestionService::class);
>>> $service->getSupportedMimeTypes();
>>> $service->isSupported('application/pdf');
```

### Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| "Unsupported MIME type" | File type not supported | Check supported types list |
| "Extraction failed" | System dependency missing | Rebuild Docker container |
| "File too large" | Exceeds max size | Increase `DOCUMENT_MAX_SIZE` |
| "Job timeout" | Processing took too long | Increase `DOCUMENT_JOB_TIMEOUT` |

## Next Steps

- [AI Backends](ai-backends.md) - Configure AI models
- [Queues & Jobs](queues-and-jobs.md) - Background processing
- [Configuration](configuration.md) - Full configuration reference
- [API Overview](api-overview.md) - Complete API documentation
