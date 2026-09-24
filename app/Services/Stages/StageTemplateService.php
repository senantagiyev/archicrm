<?php

namespace App\Services\Stages;

use App\Enums\StageStatus;
use App\Models\Project;
use App\Models\StageTemplate;
use Illuminate\Support\Carbon;

class StageTemplateService
{
    /**
     * `stages.weight` sütunu `unsignedTinyInteger`-dir — 255-dən böyük dəyər
     * SQLite-də səssizcə keçir, MySQL strict rejimində isə sorğunu qırır. Uzun
     * müddətli bənd (məs. 365 günlük müəllif nəzarəti) şablonu tamam işləməz
     * etməsin deyə çəki sütunun tavanına sıxılır — nisbi ağırlıq onsuz da qalır.
     */
    private const WEIGHT_MAX = 255;

    /**
     * Apply a template's items as stages appended after the project's existing
     * stages. Plan dates are laid out sequentially from $startFrom using each
     * item's default duration.
     *
     * İDEMPOTENTDİR: adı layihədə artıq olan bənd təkrar açılmır. Əvvəl belə
     * qoruma yox idi və «Şablon tətbiq et» düyməsi hər klikdə eyni «tətbiq
     * edildi» bildirişini verdiyi üçün operator onu təkrar basırdı — 8 mərhələli
     * plan səssizcə 16 sətrə çevrilirdi. İkiqatlanma yalnız siyahını uzatmır:
     * çəkili hazırlıq faizində hər mərhələ iki dəfə sayılır, yəni rəqəm də
     * yanlış olur. Müqayisə AD üzrədir, çünki mərhələdə şablon bəndinə istinad
     * sütunu yoxdur və operator mərhələni adı ilə tanıyır.
     */
    public function apply(Project $project, StageTemplate $template, ?Carbon $startFrom = null): void
    {
        $position = ($project->stages()->max('position') ?? 0) + 1;
        $cursor = $startFrom?->copy();

        $existing = $project->stages()
            ->pluck('name')
            ->map(fn (?string $name) => mb_strtolower(trim((string) $name)))
            ->all();

        foreach ($template->items as $item) {
            $name = (string) $item->getTranslation('name', app()->getLocale());

            if (in_array(mb_strtolower(trim($name)), $existing, true)) {
                // Bənd onsuz da plandadır. Mövcud sətri ƏZMİRİK: operator onun
                // tarixini, statusunu və məsul şəxsini əl ilə dəyişmiş ola bilər.
                continue;
            }

            $planStart = $cursor?->copy();
            $planEnd = ($cursor !== null && $item->default_duration_days)
                ? $cursor->copy()->addDays($item->default_duration_days)
                : null;

            $project->stages()->create([
                'name' => $name,
                'position' => $position++,
                'status' => StageStatus::NotStarted,
                'date_plan_start' => $planStart,
                'date_plan_end' => $planEnd,
                // Çəki yazılmasaydı hamısı defolt 1 olurdu: 60 günlük «Müəllif
                // nəzarəti» ilə 3 günlük «Təhvil» layihənin hazırlıq faizinə
                // eyni pay verirdi. Şablonun plan müddəti işin həcmi üçün
                // əlimizdəki yeganə göstəricidir; müddət verilməyibsə çəki 1
                // qalır — yəni əvvəlki davranış.
                'weight' => min(self::WEIGHT_MAX, max(1, (int) ($item->default_duration_days ?? 1))),
            ]);

            $existing[] = mb_strtolower(trim($name));

            if ($planEnd !== null) {
                $cursor = $planEnd->copy()->addDay();
            }
        }
    }
}
