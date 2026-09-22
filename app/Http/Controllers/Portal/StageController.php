<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;

/**
 * Roomix-dəki «Stages» tabı: müştəri layihənin hansı mərhələdə olduğunu
 * ayrıca səhifədə görür.
 *
 * Mərhələlər əvvəl yalnız layihə icmalının altında bir siyahı idi — orada
 * məsul şəxs və faktiki tarixlər üçün yer yox idi, halbuki müştərinin ilk
 * sualı məhz «indi kim nə edir?» olur.
 */
class StageController extends Controller
{
    use ResolvesClientProjects;

    public function index(int $project)
    {
        $project = $this->clientProject($project);

        // Məsul şəxs sətirdə göstərilir, ona görə əvvəlcədən yüklənir —
        // əks halda hər mərhələ üçün ayrıca sorğu gedərdi.
        $stages = $project->stages()
            ->with('responsible')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return view('portal.stages', compact('project', 'stages'));
    }
}
