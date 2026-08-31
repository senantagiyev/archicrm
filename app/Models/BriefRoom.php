<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BriefRoom extends Model
{
    protected $fillable = ['brief_id', 'room_type', 'label', 'position'];

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }
}
