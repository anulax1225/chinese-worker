<?php

namespace App\Enums;

enum DocumentSourceType: string
{
    case Upload = 'upload';
    case Url = 'url';
    case Paste = 'paste';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Upload => 'File Upload',
            self::Url => 'URL',
            self::Paste => 'Pasted Text',
        };
    }
}
