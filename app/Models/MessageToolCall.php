<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageToolCall extends Model
{
    /** @use HasFactory<\Database\Factories\MessageToolCallFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'tool_calls';

    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'message_id',
        'function_name',
        'arguments',
        'position',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * Get the message that owns the tool call.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Convert to array format matching ToolCall DTO.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'call_id' => $this->id,
            'name' => $this->function_name,
            'arguments' => $this->arguments,
        ];
    }
}
