<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ChatMessage extends Model
{
    /** Balon tipləri — `kind` sütununun icazəli dəyərləri. */
    public const KIND_TEXT = 'text';

    public const KIND_FILE = 'file';

    public const KIND_VOICE = 'voice';

    public const KINDS = [self::KIND_TEXT, self::KIND_FILE, self::KIND_VOICE];

    protected $fillable = [
        'project_id', 'author_type', 'author_id', 'body',
        'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'kind',
    ];

    protected $casts = [
        'attachment_size' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path);
    }

    public function isVoice(): bool
    {
        return $this->kind === self::KIND_VOICE && $this->hasAttachment();
    }

    /** Söhbət siyahısındakı qısa təsvir — fayl-yalnız mesajda mətn olmur. */
    public function preview(): string
    {
        if (filled($this->body)) {
            return (string) $this->body;
        }

        if ($this->isVoice()) {
            return t('portal.chat_voice_message');
        }

        return (string) ($this->attachment_name ?? '');
    }
}
