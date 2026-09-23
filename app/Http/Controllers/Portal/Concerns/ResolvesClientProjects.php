<?php

namespace App\Http\Controllers\Portal\Concerns;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;

/**
 * Hard scoping: every portal request resolves projects strictly through the
 * authenticated customer's client. Defence at the query level, not just UI.
 */
trait ResolvesClientProjects
{
    protected function clientProjects()
    {
        $client = Auth::guard('customer')->user()?->client;

        // The client soft-deletes but its portal accounts do not, so after the
        // studio archives a client its users still authenticate and then hit a
        // null here — every portal page, including the background poll, 500'd.
        abort_if($client === null, 403, 'Bu hesab artıq aktiv müştəriyə bağlı deyil.');

        // `draft` — layihə hələ studiyanın daxili hazırlığıdır: ad, mərhələ və
        // smeta işlənir, müştəriyə RƏSMƏN təqdim edilməyib. Portalda görünsəydi
        // müştəri yarımçıq rəqəmləri təqdimatdan əvvəl görərdi, ona görə
        // sorğunun ÖZÜNDƏ süzülür — bir səhifədə unudulan `where` qalmasın.
        //
        // `archived` qəsdən QALIR: arxiv müştəri üçün tarixçədir (yazışma,
        // smeta, sənədlər) və onu gizlətmək məlumat itkisi kimi görünür.
        // Arxivdə yalnız YAZMA bağlanır — bax `writableClientProject()`.
        return $client->projects()->where('status', '!=', ProjectStatus::Draft->value);
    }

    protected function clientProject(int|string $projectId): Project
    {
        return $this->clientProjects()->findOrFail($projectId);
    }

    /**
     * Yazan əməliyyat üçün layihə (çat göndərmə, razılaşdırma qərarı və s.).
     *
     * Niyə ayrıca metod, niyə middleware yox: portal marşrutlarının bir hissəsi
     * (brif) başqa controller-dədir və orada yazma qaydası ayrıca həll olunur;
     * marşrut səviyyəsində ümumi middleware qoysaq, oxu/yazma ayrımı route
     * faylında gizlənər və hər yeni POST-da yenidən unudular. Metod isə
     * controller-in öz kodunda görünür: `clientProject()` = oxu,
     * `writableClientProject()` = yazma.
     */
    protected function writableClientProject(int|string $projectId): Project
    {
        $project = $this->clientProject($projectId);

        // Arxiv — bağlanmış layihə. Oxumaq olar, amma yeni mesaj/qərar əlavə
        // etmək studiyanın artıq baxmadığı işə məlumat yazmaq deməkdir.
        abort_if(
            $project->status === ProjectStatus::Archived,
            403,
            'Layihə arxivlənib — yalnız baxış mümkündür.'
        );

        return $project;
    }
}
