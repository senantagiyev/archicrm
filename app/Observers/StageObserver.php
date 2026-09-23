<?php

namespace App\Observers;

use App\Models\Stage;
use App\Services\Projects\ReadinessService;

class StageObserver
{
    public function __construct(private readonly ReadinessService $readiness) {}

    public function saved(Stage $stage): void
    {
        // saveQuietly() in the service prevents recursion here.
        $stage->loadMissing('project');

        // Status dəyişəndə mərhələnin ÖZ faizi də yenidən hesablanmalıdır:
        // «Hazır» mərhələ hər iki ekranda 100%-dir, lakin keşlənmiş
        // `stages.readiness` sütunu burada yenilənməsəydi, mərhələ cədvəli
        // növbəti tapşırıq hərəkətinə qədər köhnə tapşırıq payını (məsələn 50%)
        // göstərməyə davam edirdi — layihə kartı isə artıq 100% yazırdı.
        if ($stage->wasChanged('status')) {
            // `recalculateStage()` sonda layihəni onsuz da yenidən hesablayır.
            $this->readiness->recalculateStage($stage);

            return;
        }

        $this->readiness->recalculateProject($stage->project);
    }

    public function deleted(Stage $stage): void
    {
        $stage->loadMissing('project');
        $this->readiness->recalculateProject($stage->project);
    }
}
