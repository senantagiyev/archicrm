<x-portal.shell :title="t('portal.nav_approvals')" :project="$project" active="approvals">
    <h1 class="mb-6 text-heading font-semibold">{{ t('portal.nav_approvals') }}</h1>

    <div class="space-y-4">
        @forelse ($approvals as $approval)
            <div class="rounded-ds-xl border border-black/8 bg-white p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-body font-semibold">{{ $approval->subjectLabel() }}</p>
                        <p class="mt-1 text-helper text-black/55">
                            {{ $approval->created_at->format('d.m.Y') }}
                            @if ($approval->respond_by)
                                · {{ t('portal.respond_by') }}: {{ $approval->respond_by->format('d.m.Y') }}
                            @endif
                        </p>
                        @if ($approval->approvable instanceof \App\Models\BudgetLine || $approval->approvable instanceof \App\Models\ProcurementItem)
                            <p class="mt-2 text-title font-semibold">{{ number_format((float) $approval->approvable->total, 2, '.', ' ') }} ₼</p>
                        @endif
                    </div>

                    @if ($approval->status === \App\Enums\ApprovalStatus::Pending)
                        <div class="flex items-center gap-2">
                            <form method="post" action="{{ route('portal.approvals.decide', $approval) }}">
                                @csrf
                                <input type="hidden" name="decision" value="approve">
                                <button class="ui-btn ui-btn-primary h-10 px-5 text-[14px] font-semibold" data-hover="true">
                                    {{ t('portal.approve') }}
                                </button>
                            </form>
                            <button type="button"
                                onclick="this.closest('div').parentElement.parentElement.querySelector('[data-reject-form]').toggleAttribute('hidden')"
                                class="ui-btn ui-btn-danger h-10 px-5 text-[14px] font-semibold" data-hover="true">
                                {{ t('portal.reject') }}
                            </button>
                        </div>
                    @else
                        <span @class([
                            'rounded-pill px-3 py-1 text-helper font-medium',
                            'bg-ok-soft text-ok' => $approval->status === \App\Enums\ApprovalStatus::Approved,
                            'bg-error-soft text-error' => $approval->status === \App\Enums\ApprovalStatus::Rejected,
                        ])>{{ $approval->status->translatedLabel() }}</span>
                    @endif
                </div>

                @if ($approval->status === \App\Enums\ApprovalStatus::Pending)
                    <form method="post" action="{{ route('portal.approvals.decide', $approval) }}"
                        data-reject-form hidden class="mt-4 border-t border-black/8 pt-4">
                        @csrf
                        <input type="hidden" name="decision" value="reject">
                        <label class="mb-1.5 block text-helper font-semibold">{{ t('portal.reject_reason') }}</label>
                        <textarea name="comment" rows="2" required
                            class="w-full rounded-ds border border-black/20 px-3.5 py-2.5 text-body outline-none focus:border-ink"></textarea>
                        <button class="ui-btn ui-btn-danger mt-3 h-10 px-5 text-[14px] font-semibold" data-hover="true">
                            {{ t('portal.reject_confirm') }}
                        </button>
                    </form>
                @elseif ($approval->comment)
                    <p class="mt-3 border-t border-black/8 pt-3 text-body text-black/70">
                        <span class="font-semibold">{{ t('portal.comment') }}:</span> {{ $approval->comment }}
                    </p>
                @endif
            </div>
        @empty
            <div class="rounded-ds-xl border border-black/8 bg-white px-5 py-12 text-center text-body text-black/55">
                {{ t('portal.no_approvals') }}
            </div>
        @endforelse
    </div>
</x-portal.shell>
