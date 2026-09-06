<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

class BriefQuestion extends Model
{
    use HasTranslations;

    protected $fillable = [
        'brief_section_id', 'key', 'label', 'help', 'type', 'options', 'skip_logic',
        'is_required', 'allows_designer_choice', 'position', 'active',
    ];

    public array $translatable = ['label', 'help'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'skip_logic' => 'array',
            'is_required' => 'boolean',
            'allows_designer_choice' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * TZ §8.8 skip logic: a question is shown only when its condition matches a
     * previously answered value. No rule → always shown. $valuesByKey maps other
     * questions' keys to their current answer value.
     *
     * @param  array<string, mixed>  $valuesByKey
     */
    public function shouldShow(array $valuesByKey): bool
    {
        $rule = $this->skip_logic;
        if (blank($rule) || blank($rule['question'] ?? null)) {
            return true;
        }

        $actual = $valuesByKey[$rule['question']] ?? null;
        $expected = $rule['value'] ?? null;

        return match ($rule['operator'] ?? 'equals') {
            'not_equals' => $actual !== $expected,
            'in' => in_array($actual, (array) $expected, true),
            default => is_array($actual)
                ? in_array($expected, $actual, true)   // multiselect contains
                : $actual === $expected,
        };
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
