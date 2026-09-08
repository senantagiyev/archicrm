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
            'not_equals' => is_array($actual)
                ? ! in_array($expected, $actual, true)
                : $actual !== $expected,
            'in' => in_array($actual, (array) $expected, true),
            // Spec Part 10 №9: "≥2 adults" style numeric thresholds.
            'gte' => is_numeric($actual) && (float) $actual >= (float) $expected,
            'lte' => is_numeric($actual) && (float) $actual <= (float) $expected,
            // Spec Part 10 №10: per-item comment shown once anything is picked.
            'filled' => filled($actual),
            // Spec Part 10 №1/№18: room_inventory is {room_type: count}.
            'has_room' => (int) (is_array($actual) ? ($actual[$expected] ?? 0) : 0) > 0,
            // Spec Part 10 №17: matrix answers are {row: column}.
            'matrix_row_filled' => is_array($actual) && filled($actual[$expected] ?? null),
            default => is_array($actual)
                ? in_array($expected, $actual, true)   // multiselect contains
                : $actual === $expected,
        };
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(BriefSection::class, 'brief_section_id');
    }

    /** Localized label for one option value; also looks inside matrix rows/columns. */
    public function optionLabel(string $value): string
    {
        $options = $this->options ?? [];
        $flat = array_is_list($options)
            ? $options
            : array_merge($options['rows'] ?? [], $options['columns'] ?? []);

        foreach ($flat as $option) {
            if (($option['value'] ?? null) === $value) {
                return $option['label'][app()->getLocale()] ?? $option['label']['az'] ?? $value;
            }
        }

        return $value;
    }

    /**
     * Human-readable rendering of a stored answer — one place for every type,
     * shared by the summary screen, the PDF export and the staff panel.
     */
    public function displayValue(mixed $value): string
    {
        if (blank($value)) {
            return '';
        }

        return match ($this->type) {
            'boolean' => $value === '1' || $value === true ? t('portal.yes') : t('portal.no'),
            'consent' => $value === '1' ? '✓' : '—',
            'select', 'image_select' => $this->optionLabel((string) $value),
            'budget_range' => trim(($value['min'] ?? '—').' – '.($value['max'] ?? '—').' '.($value['currency'] ?? '')),
            'file' => $value['name'] ?? ($value['path'] ?? ''),
            'matrix' => collect($value)
                ->filter(fn ($col) => filled($col))
                ->map(fn ($col, $row) => $this->optionLabel((string) $row).': '.$this->optionLabel((string) $col))
                ->implode(' · '),
            'room_inventory' => collect($value)
                ->filter(fn ($n) => (int) $n > 0)
                ->map(fn ($n, $type) => $this->optionLabel((string) $type).((int) $n > 1 ? ' ×'.$n : ''))
                ->implode(', '),
            'color_swatch' => trim(
                (filled($value['base'] ?? null) ? 'Fon: '.implode(', ', $value['base']) : '')
                .(filled($value['accent'] ?? null) ? '  Akcent: '.implode(', ', $value['accent']) : '')
            ),
            default => is_array($value)
                ? collect($value)->map(fn ($v) => $this->optionLabel((string) $v))->implode(', ')
                : (string) $value,
        };
    }
}
