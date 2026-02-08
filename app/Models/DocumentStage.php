<?php

namespace App\Models;

use App\Enums\DocumentStageType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentStage extends Model
{
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'document_id',
        'stage',
        'content',
        'metadata',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => DocumentStageType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the document that this stage belongs to.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get a preview of the content (first N characters).
     */
    public function getPreview(int $length = 500): string
    {
        if (mb_strlen($this->content) <= $length) {
            return $this->content;
        }

        return mb_substr($this->content, 0, $length).'...';
    }

    /**
     * Get the character count of the content.
     */
    public function getCharacterCount(): int
    {
        return mb_strlen($this->content);
    }

    /**
     * Get the word count of the content.
     */
    public function getWordCount(): int
    {
        return str_word_count($this->content);
    }
}
