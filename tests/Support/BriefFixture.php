<?php

namespace Tests\Support;

use App\Models\Brief;
use App\Models\Project;
use App\Services\Brief\BriefService;

/**
 * Testlər üçün TƏQDİM EDİLMİŞ brif.
 *
 * Portal yalnız studiyanın müştəriyə göndərdiyi brifi göstərir (`presented_at`).
 * `forProject()` isə qaralama yaradır — yəni portal testində ondan sonra
 * dərhal səhifə açmaq 404 verir. Bu köməkçi ikisini birləşdirir: qaralamanı
 * yaradır və bildiriş göndərmədən təqdim edilmiş sayır.
 */
final class BriefFixture
{
    public static function present(Project $project): Brief
    {
        $service = app(BriefService::class);

        $brief = $service->forProject($project);
        $service->markPresented($brief);

        return $brief->fresh();
    }
}
