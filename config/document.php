<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Document Extraction Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for extracting text content from various file formats.
    |
    */

    'extraction' => [
        'max_file_size' => env('DOCUMENT_MAX_SIZE', 50 * 1024 * 1024), // 50MB
        'timeout' => env('DOCUMENT_EXTRACTION_TIMEOUT', 60),

        'pdf' => [
            'driver' => env('PDF_EXTRACTOR_DRIVER', 'pdfparser'), // pdfparser|pdftotext
            'pdftotext_path' => env('PDFTOTEXT_PATH', '/usr/bin/pdftotext'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported MIME Types
    |--------------------------------------------------------------------------
    |
    | List of MIME types that can be processed by the document pipeline.
    | Additional extractors can be registered for more file types.
    |
    */

    'supported_types' => [
        'text/plain',
        'text/markdown',
        'text/x-markdown',
        'text/csv',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/html',
        'application/xhtml+xml',
        'application/json',
        'application/xml',
        'text/xml',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Cleaning Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the content cleaning pipeline that removes junk
    | and normalizes text before it's processed by the AI.
    |
    */

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

    /*
    |--------------------------------------------------------------------------
    | Chunking Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for splitting documents into chunks that fit within
    | LLM context limits while preserving document structure.
    |
    */

    'chunking' => [
        'default_max_tokens' => env('CHUNK_MAX_TOKENS', 1000),
        'default_overlap_tokens' => env('CHUNK_OVERLAP_TOKENS', 100),
        'min_chunk_tokens' => 50,
        'respect_sections' => true,
        'token_estimation' => env('TOKEN_ESTIMATION_METHOD', 'heuristic'), // heuristic|tiktoken
    ],

    /*
    |--------------------------------------------------------------------------
    | Processing Configuration
    |--------------------------------------------------------------------------
    |
    | General configuration for document processing behavior.
    |
    */

    'processing' => [
        'job_timeout' => env('DOCUMENT_JOB_TIMEOUT', 300), // 5 minutes
        'job_tries' => 1, // No retry to avoid duplicate processing
        'store_all_stages' => env('DOCUMENT_STORE_ALL_STAGES', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for storing document-related files and content.
    |
    */

    'storage' => [
        'disk' => env('DOCUMENT_STORAGE_DISK', 'local'),
        'path' => 'documents',
    ],
];
