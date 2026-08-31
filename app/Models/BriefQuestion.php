<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class BriefQuestion extends Model
{
    use HasTranslations;

    protected $fillable = [
        'brief_section_id', 'key', 'label', 'help', 'type', 'options',
        'is_required', 'allows_designer_choice', 'position', 'active',
    ];

    public array $translatable = ['label', 'help'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'allows_designer_choice' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(BriefSection::class, 'brief_section_id');
    }

    /** Localized label for one option value. */
    public function optionLabel(string $value): string
    {
        foreach ($this->options ?? [] as $option) {
            if (($option['value'] ?? null) === $value) {
                return $option['label'][app()->getLocale()] ?? $option['label']['az'] ?? $value;
            }
        }

        return $value;
    }
}
