<?php

namespace App\Support;

class Csv
{
    /**
     * Excel/LibreOffice `=`, `+`, `-`, `@` (və nəzarət simvolları) ilə başlayan
     * xananı DÜSTUR kimi oxuyur. Sahələri bugün yalnız studiya işçisi doldurur,
     * amma ixrac faylı müştəriyə gedir: orada `=HYPERLINK(...)` və ya xarici
     * sorğu açan düstur qurmaq mümkündür.
     *
     * Ona görə belə xananın əvvəlinə tək dırnaq qoyulur — dəyər gözə eyni
     * görünür, lakin hesablama kimi icra olunmur.
     *
     * @see https://owasp.org/www-community/attacks/CSV_Injection
     */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        if ($value === '') {
            return $value;
        }

        // Öndəki boşluqlar Excel-i aldatmır — düstur yenə işləyir, ona görə
        // yoxlama kəsilmiş dəyər üzərində aparılır.
        return preg_match('/^[\s\x00-\x1F]*[=+\-@]/', $value) === 1
            ? "'".$value
            : $value;
    }

    /**
     * Bütöv sətri təmizləyir — ixracda hər xananı ayrıca yadda saxlamaq
     * unudula bilər, sətir səviyyəsi isə bir yerdə tətbiq olunur.
     *
     * @param  array<int, mixed>  $row
     * @return array<int, string>
     */
    public static function row(array $row): array
    {
        return array_map(static fn ($cell) => static::cell($cell), $row);
    }
}
