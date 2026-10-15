<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Services\Portal\ClientOverviewService;

class ProjectController extends Controller
{
    use ResolvesClientProjects;

    public function index()
    {
        // Alt-layihə kartında valideynin adı göstərilir — əvvəlcədən yüklənməsə
        // hər sətir üçün ayrıca sorğu gedərdi.
        $projects = $this->clientProjects()->with('parent')->latest()->get();

        if ($projects->count() === 1) {
            return redirect()->route('portal.projects.show', $projects->first());
        }

        return view('portal.projects.index', compact('projects'));
    }

    /**
     * «İcmal» — portalın birinci bölməsi (TZ «Layihə naviqasiyası» §1–2):
     * «Sizdən gözlənilən əməliyyatlar», cari mərhələ və yaxın son tarixlər,
     * iştirakçılar, müştəri üçün təhlükəsiz hadisə lenti. Ödənişlər, sənədlər
     * və smeta bölmə deyil — buradan ikinci dərəcəli keçidlərlə açılır.
     */
    public function show(int $project, ClientOverviewService $overview)
    {
        $project = $this->clientProject($project);
        $project->load(['stages.responsible:id,name', 'manager', 'brief']);

        $pendingApprovals = $project->approvals()
            ->where('status', ApprovalStatus::Pending->value)
            ->count();

        // Brif kartı yalnız studiya brifi TƏQDİM EDƏNDƏN sonra görünür. Bir neçə
        // brif göndərilibsə, kart onların orta doldurulma faizini göstərir.
        $presentedBriefs = $project->briefs()->whereNotNull('presented_at')->get(['id', 'progress']);
        $briefPresented = $presentedBriefs->isNotEmpty();
        $briefProgress = (int) round($presentedBriefs->avg('progress') ?? 0);
        $documentsCount = $project->documents()->visibleToClient()->count();
        $paymentsDue = $project->payments()
            ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Overdue->value])
            ->count();

        return view('portal.projects.show', [
            ...compact('project', 'pendingApprovals', 'briefProgress', 'briefPresented', 'documentsCount', 'paymentsDue'),
            'actions' => $overview->pendingActions($project),
            'currentStage' => $overview->currentStage($project),
            'deadlines' => $overview->upcomingDeadlines($project),
            'participants' => $overview->participants($project),
            'feed' => $overview->feed($project),
        ]);
    }
}
