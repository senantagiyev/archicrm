<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class StageTemplateItem extends Model
{
    use HasTranslations;

    protected $fillable = ['stage_template_id', 'name', 'position', 'default_duration_days', 'default_categories'];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        // Şablondan açılan mərhələyə köçürülür (StageTemplateService::apply):
        // bu kateqoriyalı materiallar üçün mərhələ standart seçilir.
        return ['default_categories' => 'array'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(StageTemplate::class, 'stage_template_id');
    }
}
