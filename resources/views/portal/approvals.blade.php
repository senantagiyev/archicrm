<x-portal.shell :title="t('portal.nav_approvals')" :project="$project" active="approvals">
    <h1 class="mb-6 text-heading font-semibold">{{ t('portal.nav_approvals') }}</h1>

    <div class="space-y-4">
        @forelse ($approvals as $approval)
            <div class="rounded-ds-xl border border-black/8 bg-white p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="flex flex-wrap items-center gap-2 text-body font-semibold">
                            {{ $approval->subjectLabel() }}
                            {{-- Roomix kartında versiya başlığın yanındadır: müştəri
                                 neçənci dəfə baxdığını dərhal görür. --}}
                            <span class="rounded-pill bg-neutral-soft px-2 py-0.5 text-helper font-bold text-black/55">
                                v{{ $approval->version }}
                            </span>
                        </p>
                        <p class="mt-1 text-helper text-black/55">
                            {{ $approval->created_at->format('d.m.Y') }}
                            @if ($approval->respond_by)
                                · <span class="{{ $approval->isOverdue() ? 'font-semibold text-error' : '' }}">
                                    {{ t('portal.respond_by') }}: {{ $approval->respond_by->format('d.m.Y') }}
                                </span>
                            @endif
                            @if ($approval->daysWaiting() > 0)
                                · {{ t('portal.waiting_days', ['days' => $approval->daysWaiting()]) }}
                            @endif
                        </p>
                        @if ($approval->approvable instanceof \App\Models\BudgetLine || $approval->approvable instanceof \App\Models\ProcurementItem)
                            <p class="mt-2 text-title font-semibold">{{ number_format((float) $approval->approvable->total, 2, '.', ' ') }} ₼</p>
                        @endif
                    </div>

                    @if ($approval->status === \App\Enums\ApprovalStatus::Pending)
                        <div class="flex items-center gap-2">
                            {{-- Variantlı razılaşdırmada tək «Təsdiqlə» düyməsi yoxdur:
                                 seçim aşağıdakı kartlardan gedir, çünki hansının
                                 seçildiyi bilinmədən təsdiq dizaynerə heç nə demir. --}}
                            @unless ($approval->hasVariants())
                                <form method="post" action="{{ route('portal.approvals.decide', $approval) }}">
                                    @csrf
                                    <input type="hidden" name="decision" value="approve">
                                    <button class="ui-btn ui-btn-primary h-10 px-5 text-[14px] font-semibold" data-hover="true">
                                        {{ t('portal.approve') }}
                                    </button>
                                </form>
                            @endunless
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

                {{-- Çoxvariantlı razılaşdırma: müştəri variantlardan birini seçir
                     (Roomix «Bedroom: two options» kartı). Hər variant öz formasıdır,
                     yəni seçim bir kliklə yekunlaşır — ara addım yoxdur. --}}
                @if ($approval->hasVariants())
                    <div class="mt-4 grid gap-3 border-t border-black/8 pt-4 sm:grid-cols-2">
                        @foreach ($approval->variants as $variant)
                            @php $picked = $approval->chosen_variant === ($variant['key'] ?? null); @endphp
                            <div @class([
                                'rounded-ds-lg border p-4',
                                'border-ok bg-ok-soft' => $picked,
                                'border-black/10' => ! $picked,
                            ])>
                                <p class="text-body font-semibold">{{ $variant['label'] ?? $variant['key'] }}</p>
                                @if (! empty($variant['note']))
                                    <p class="mt-1 text-helper leading-relaxed text-black/60">{{ $variant['note'] }}</p>
                                @endif

                                @if ($approval->status === \App\Enums\ApprovalStatus::Pending)
                                    <form method="post" action="{{ route('portal.approvals.decide', $approval) }}" class="mt-3">
                                        @csrf
                                        <input type="hidden" name="decision" value="approve">
                                        <input type="hidden" name="variant" value="{{ $variant['key'] }}">
                                        <button class="ui-btn ui-btn-primary h-9 px-4 text-[13px] font-semibold" data-hover="true">
                                            {{ t('portal.choose_variant') }}
                                        </button>
                                    </form>
                                @elseif ($picked)
                                    <p class="mt-2 text-helper font-semibold text-ok">✓ {{ t('portal.variant_chosen') }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

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

                {{-- Roomix-dəki «Approval history»: əvvəlki dövrlər ayrıca sətir
                     kimi qalır, ona görə tarixçə elə həmin sətirlərdir. --}}
                @php $history = $approval->history()->get(); @endphp
                @if ($history->isNotEmpty())
                    <details class="mt-3 border-t border-black/8 pt-3">
                        <summary class="cursor-pointer text-helper font-semibold text-black/55">
                            {{ t('portal.approval_history', ['count' => $history->count()]) }}
                        </summary>
                        <ul class="mt-2 space-y-1.5">
                            @foreach ($history as $past)
                                <li class="flex flex-wrap items-baseline gap-2 text-helper text-black/55">
                                    <span class="font-semibold text-black/70">v{{ $past->version }}</span>
                                    <span>{{ $past->created_at->format('d.m.Y') }}</span>
                                    <span>· {{ $past->status->translatedLabel() }}</span>
                                    @if ($past->comment)<span class="min-w-0">· {{ $past->comment }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </div>
        @empty
            <div class="rounded-ds-xl border border-black/8 bg-white px-5 py-12 text-center text-body text-black/55">
                {{ t('portal.no_approvals') }}
            </div>
        @endforelse
    </div>
</x-portal.shell>
