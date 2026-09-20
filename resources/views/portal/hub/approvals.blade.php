<x-portal.shell :title="t('portal.nav_approvals')" active="approvals">
    <h1 class="mb-1 font-b2b text-[26px] font-extrabold tracking-tight">{{ t('portal.nav_approvals') }}</h1>
    <p class="mb-6 text-[14px] text-black/50">{{ t('portal.approvals_all_intro') }}</p>

    <div class="space-y-4">
        @forelse ($approvals as $approval)
            <div class="rounded-[16px] border border-black/8 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        {{-- Layihə adı hər sətirdə: qlobal siyahıda kontekst olmadan
                             pozisiya nəyə aid olduğu bilinmir. --}}
                        <a href="{{ route('portal.approvals', $approval->project_id) }}"
                           class="inline-flex max-w-full items-center gap-1.5 truncate rounded-pill bg-neutral-soft px-3 py-1 text-[13px] font-semibold text-black/60 transition-colors hover:bg-sel-bg hover:text-ink">
                            <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-yellow"></span>
                            <span class="truncate">{{ $approval->project?->name ?? '—' }}</span>
                        </a>

                        <p class="mt-2 text-[15px] font-bold">{{ $approval->subjectLabel() }}</p>
                        <p class="mt-0.5 text-[13px] text-black/45">
                            {{ $approval->created_at->format('d.m.Y') }}
                            @if ($approval->respond_by)
                                · {{ t('portal.respond_by') }}: {{ $approval->respond_by->format('d.m.Y') }}
                            @endif
                        </p>
                        @if ($approval->approvable instanceof \App\Models\BudgetLine || $approval->approvable instanceof \App\Models\ProcurementItem)
                            <p class="mt-2 text-lg font-bold">{{ number_format((float) $approval->approvable->total, 2, '.', ' ') }} ₼</p>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        <form method="post" action="{{ route('portal.approvals.decide', $approval) }}">
                            @csrf
                            <input type="hidden" name="decision" value="approve">
                            <button class="ui-btn ui-btn-primary h-10 px-5 text-[13px] font-bold" data-hover="true">
                                {{ t('portal.approve') }}
                            </button>
                        </form>
                        {{-- Rədd üçün şərh MƏCBURİDİR — o forma layihənin öz
                             razılaşdırma səhifəsindədir, burada dublikat edilmir. --}}
                        <a href="{{ route('portal.approvals', $approval->project_id) }}"
                           class="ui-btn ui-btn-danger h-10 px-5 text-[13px] font-bold" data-hover="true">
                            {{ t('portal.reject') }}
                        </a>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-[16px] border border-black/8 bg-white px-5 py-12 text-center">
                <p class="text-[14px] text-black/40">{{ t('portal.no_pending_approvals') }}</p>
            </div>
        @endforelse
    </div>
</x-portal.shell>
