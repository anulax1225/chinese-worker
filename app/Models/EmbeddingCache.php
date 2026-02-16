<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmbeddingCache extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'content_hash',
        'embedding_raw',
        'embedding_model',
        'language',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding_raw' => 'array',
        ];
    }

    /**
     * Find cached embedding by content hash and model.
     */
    public static function findByHash(string $hash, string $model): ?self
    {
        return static::where('content_hash', $hash)
            ->where('embedding_model', $model)
            ->first();
    }
}
