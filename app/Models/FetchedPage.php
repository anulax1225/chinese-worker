<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FetchedPage extends Model
{
    /** @use HasFactory<\Database\Factories\FetchedPageFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'url',
        'url_hash',
        'title',
        'content_type',
        'content_hash',
        'text',
        'fetched_at',
        'expires_at',
        'embedded_at',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'expires_at' => 'datetime',
            'embedded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the chunks for this fetched page.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(FetchedPageChunk::class)->orderBy('chunk_index');
    }

    /**
     * Check whether the stored content is still fresh.
     */
    public function isExpired(): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    /**
     * Check whether the page has been embedded.
     */
    public function isEmbedded(): bool
    {
        return $this->embedded_at !== null;
    }

    /**
     * Generate the URL hash used as a unique lookup key.
     */
    public static function hashUrl(string $url): string
    {
        return hash('sha256', $url);
    }

    /**
     * Generate the content hash used for staleness detection.
     */
    public static function hashContent(string $text): string
    {
        return hash('sha256', $text);
    }
}
