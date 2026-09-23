<?php

namespace App\Http\Controllers\Portal;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Portal\Concerns\ResolvesClientProjects;
use App\Models\Approval;
use App\Services\Approvals\ApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ApprovalController extends Controller
{
    use ResolvesClientProjects;

    public function index(int $project)
    {
        $project = $this->clientProject($project);

        $approvals = $project->approvals()
            ->whereIn('status', [ApprovalStatus::Pending->value, ApprovalStatus::Approved->value, ApprovalStatus::Rejected->value])
            ->with(['approvable'])
            // Pending first. Plain SQL rather than MySQL's field(), which made this
            // page — the one screen where the client commits to money — impossible
            // to cover with a test.
            ->orderByDesc(DB::raw("case when status = '".ApprovalStatus::Pending->value."' then 1 else 0 end"))
            ->latest()
            ->get();

        return view('portal.approvals', compact('project', 'approvals'));
    }

    public function decide(Request $request, Approval $approval, ApprovalService $service)
    {
        // Scoping: the approval must belong to one of this customer's projects.
        // Qərar YAZAN əməliyyatdır — arxivlənmiş layihədə bağlıdır.
        $this->writableClientProject($approval->project_id);

        abort_unless($approval->status === ApprovalStatus::Pending, 403);

        // Variantın özü deyil, yalnız AÇARI qəbul edilir. Açarın mövcudluğunu
        // servis də yoxlayır, amma o, `InvalidArgumentException` atır — yəni
        // yad açar istifadəçiyə 500 kimi qayıdırdı. Siyahı validasiyaya
        // BURADA, razılaşdırmanın öz variantlarına qarşı verilir: nəticə 422 və
        // anlaşılan mesaj olur. Servisdəki yoxlama son sədd kimi yerində qalır
        // (razılaşdırma servisə portaldan başqa yerlərdən də gəlir).
        $allowedVariants = collect($approval->variants ?? [])
            ->pluck('key')
            ->filter(fn ($key) => is_string($key) && $key !== '')
            ->values()
            ->all();

        $variantRules = ['nullable', 'string', 'max:64'];

        // Variantlı razılaşdırmanı təsdiq etmək = birini seçmək (servisdəki
        // qayda ilə eyni), ona görə təsdiqdə açar məcburidir.
        if ($approval->hasVariants()) {
            array_unshift($variantRules, 'required_if:decision,approve');
        }

        // Siyahı varsa açar yalnız oradan ola bilər; siyahı yoxdursa
        // razılaşdırma adi bəli/xeyrdir və açar onsuz da istifadə olunmur.
        if ($allowedVariants !== []) {
            $variantRules[] = Rule::in($allowedVariants);
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'comment' => ['required_if:decision,reject', 'nullable', 'string', 'max:2000'],
            'variant' => $variantRules,
        ], [
            'comment.required_if' => t('portal.reject_comment_required'),
            'variant.in' => t('portal.variant_invalid'),
            'variant.required_if' => t('portal.variant_required'),
        ]);

        $service->decide(
            $approval,
            $validated['decision'] === 'approve',
            $validated['comment'] ?? null,
            Auth::guard('customer')->user(),
            $validated['variant'] ?? null,
        );

        return back()->with('status', $validated['decision'] === 'approve'
            ? t('portal.approved_ok')
            : t('portal.rejected_ok'));
    }
}
