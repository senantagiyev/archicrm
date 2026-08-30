<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class StageTemplateItem extends Model
{
    use HasTranslations;

    protected $fillable = ['stage_template_id', 'name', 'position', 'default_duration_days'];

    public array $translatable = ['name'];

    public function template(): BelongsTo
    {
        return $this->belongsTo(StageTemplate::class, 'stage_template_id');
    }
}
