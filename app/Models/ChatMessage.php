<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ChatMessage extends Model
{
    protected $fillable = ['project_id', 'author_type', 'author_id', 'body'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
