<x-portal.shell :title="t('portal.nav_approvals')" :project="$project" active="approvals">
    <h1 class="mb-6 text-2xl font-bold">{{ t('portal.nav_approvals') }}</h1>

    <div class="space-y-4">
        @forelse ($approvals as $approval)
            <div class="rounded-ds-md border border-black/10 bg-white p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-sm font-bold">{{ $approval->subjectLabel() }}</p>
                        <p class="mt-0.5 text-[12px] text-black/40">
                            {{ $approval->created_at->format('d.m.Y') }}
                            @if ($approval->respond_by)
                                · {{ t('portal.respond_by') }}: {{ $approval->respond_by->format('d.m.Y') }}
                            @endif
                        </p>
                        @if ($approval->approvable instanceof \App\Models\BudgetLine || $approval->approvable instanceof \App\Models\ProcurementItem)
                            <p class="mt-2 text-lg font-bold">{{ number_format((float) $approval->approvable->total, 2, '.', ' ') }} ₼</p>
                        @endif
                    </div>

                    @if ($approval->status === \App\Enums\ApprovalStatus::Pending)
                        <div class="flex items-center gap-2">
                            <form method="post" action="{{ route('portal.approvals.decide', $approval) }}">
                                @csrf
                                <input type="hidden" name="decision" value="approve">
                                <button class="ui-btn ui-btn-primary h-10 px-5 text-[13px] font-bold" data-hover="true">
                                    {{ t('portal.approve') }}
                                </button>
                            </form>
                            <button type="button"
                                onclick="this.closest('div').parentElement.parentElement.querySelector('[data-reject-form]').toggleAttribute('hidden')"
                                class="ui-btn ui-btn-danger h-10 px-5 text-[13px] font-bold" data-hover="true">
                                {{ t('portal.reject') }}
                            </button>
                        </div>
                    @else
                        <span @class([
                            'rounded-pill px-3 py-1 text-[12px] font-semibold',
                            'bg-ok-soft text-ok' => $approval->status === \App\Enums\ApprovalStatus::Approved,
                            'bg-error-soft text-error' => $approval->status === \App\Enums\ApprovalStatus::Rejected,
                        ])>{{ $approval->status->translatedLabel() }}</span>
                    @endif
                </div>

                @if ($approval->status === \App\Enums\ApprovalStatus::Pending)
                    <form method="post" action="{{ route('portal.approvals.decide', $approval) }}"
                        data-reject-form hidden class="mt-4 border-t border-black/10 pt-4">
                        @csrf
                        <input type="hidden" name="decision" value="reject">
                        <label class="mb-1.5 block text-[13px] font-semibold">{{ t('portal.reject_reason') }}</label>
                        <textarea name="comment" rows="2" required
                            class="w-full rounded-ds border border-black/20 px-3.5 py-2.5 text-sm outline-none focus:border-ink"></textarea>
                        <button class="ui-btn ui-btn-danger mt-3 h-10 px-5 text-[13px] font-bold" data-hover="true">
                            {{ t('portal.reject_confirm') }}
                        </button>
                    </form>
                @elseif ($approval->comment)
                    <p class="mt-3 border-t border-black/10 pt-3 text-sm text-black/60">
                        <span class="font-semibold">{{ t('portal.comment') }}:</span> {{ $approval->comment }}
                    </p>
                @endif
            </div>
        @empty
            <div class="rounded-ds-md border border-black/10 bg-white px-5 py-10 text-center text-sm text-black/40">
                {{ t('portal.no_approvals') }}
            </div>
        @endforelse
    </div>
</x-portal.shell>
