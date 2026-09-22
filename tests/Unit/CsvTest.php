<?php

namespace Tests\Unit;

use App\Support\Csv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CSV ixracı müştəriyə gedir, yəni xanalar Excel-də AÇILIR. `=`, `+`, `-`, `@`
 * ilə başlayan dəyər orada düstur kimi icra olunur — bu testlər həmin
 * çevrilmənin qarşısını alan prefiksi qoruyur.
 */
class CsvTest extends TestCase
{
    public static function dangerousCells(): array
    {
        return [
            'bərabərdir' => ['=1+1'],
            'artı' => ['+1'],
            'minus' => ['-1'],
            'at' => ['@SUM(A1)'],
            'hiperlink' => ['=HYPERLINK("http://nümunə.az","klik")'],
            // Öndəki boşluq və tab Excel-i aldatmır — düstur yenə işləyir.
            'boşluqla' => ['   =1+1'],
            'tabla' => ["\t=1+1"],
        ];
    }

    #[DataProvider('dangerousCells')]
    public function test_a_formula_like_cell_is_quoted(string $value): void
    {
        $this->assertSame("'".$value, Csv::cell($value));
    }

    public function test_ordinary_values_are_left_alone(): void
    {
        foreach (['Divar boyası', '900.00', 'm²', 'Mətbəx № 2', ''] as $value) {
            $this->assertSame($value, Csv::cell($value));
        }
    }

    public function test_a_whole_row_is_sanitised(): void
    {
        $this->assertSame(
            ['Divar', "'=1+1", '12.00'],
            Csv::row(['Divar', '=1+1', '12.00']),
        );
    }
}
