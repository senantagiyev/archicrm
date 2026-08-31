<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BriefSectionState extends Model
{
    protected $fillable = ['brief_id', 'brief_section_id', 'brief_room_id', 'status', 'submitted_at'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function brief(): BelongsTo
    {
        return $this->belongsTo(Brief::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(BriefSection::class, 'brief_section_id');
    }
}
