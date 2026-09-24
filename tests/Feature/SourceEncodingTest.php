<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Mənbə kodunda ikiqat kodlaşdırılmış mətn olmamalıdır.
 *
 * Bir dəfə belə olmuşdu: bildiriş faylları UTF-8 baytları Windows-1252 kimi
 * oxunub yenidən UTF-8-ə yazılmış vəziyyətdə repoya düşdü. Nəticədə müştəriyə
 * gedən dəvət məktubunda «layihÉ™ portalÄ±na dÉ™vÉ™t» yazılırdı — kod işləyirdi,
 * test də yaşıl idi, çünki heç bir testdə mətnin ÖZÜ yoxlanılmırdı. Səhv yalnız
 * real məktubda görünürdü.
 *
 * Yoxlama markerlərin siyahısına söykənmir (belə siyahı həmişə natamam olur):
 * sətir Windows-1252-yə İTKİSİZ çevrilirsə və nəticə hələ də DÜZGÜN UTF-8-dirsə,
 * deməli o sətir ikiqat kodlaşdırılıb. Düzgün yazılmış mətn belə çevriləndə tək
 * baytlara düşür və UTF-8 kimi etibarsız olur, yəni səhv siqnal vermir.
 */
class SourceEncodingTest extends TestCase
{
    /** @var array<int, string> */
    private const ROOTS = ['app', 'resources', 'database', 'routes', 'config', 'tests'];

    public function test_no_source_file_contains_double_encoded_text(): void
    {
        $broken = [];

        foreach (self::ROOTS as $root) {
            foreach (File::allFiles(base_path($root)) as $file) {
                if (! in_array($file->getExtension(), ['php', 'md', 'json'], true)) {
                    continue;
                }

                foreach (explode("\n", $file->getContents()) as $number => $line) {
                    if ($this->isDoubleEncoded($line)) {
                        $broken[] = $file->getRelativePathname().':'.($number + 1).' → '.trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $broken,
            "Bu sətirlər ikiqat kodlaşdırılıb (UTF-8 → Windows-1252 → UTF-8):\n".implode("\n", $broken),
        );
    }

    private function isDoubleEncoded(string $line): bool
    {
        if ($line === '' || mb_check_encoding($line, 'ASCII')) {
            return false;
        }

        $decoded = @mb_convert_encoding($line, 'Windows-1252', 'UTF-8');

        if ($decoded === false || $decoded === $line) {
            return false;
        }

        // Nəticə düzgün UTF-8 olmalı VƏ geri çevrilmə orijinalı bərpa etməlidir.
        return mb_check_encoding($decoded, 'UTF-8')
            && mb_convert_encoding($decoded, 'UTF-8', 'Windows-1252') === $line
            && preg_match('/[əıışğçöüİƏŞĞÇÖÜ]/u', $decoded) === 1;
    }
}
