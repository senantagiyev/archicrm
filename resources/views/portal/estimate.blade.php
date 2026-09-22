<x-portal.shell :title="t('portal.nav_estimate')" :project="$project" active="documents">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-heading font-semibold">{{ t('portal.nav_estimate') }}</h1>

        {{-- Roomix-də «Download Excel» cədvəlin ÜSTÜNDƏDİR — müştəri uzun
             siyahının sonuna sürüşmədən faylı götürə bilsin. Bizdə CSV-dir
             (Excel onu birbaşa açır), ona görə etiket də «CSV»dir. --}}
        <a href="{{ route('portal.estimate.export', $project) }}"
           class="ui-btn inline-flex items-center gap-2 rounded-ds-lg bg-accent-dark px-4 py-2.5 text-helper font-semibold text-white transition-colors hover:bg-accent-dark/85">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3v12M7 11l5 5 5-5M4 20h16"/>
            </svg>
            {{ t('portal.estimate_download') }}
        </a>
    </div>

    {{-- Sürüşmə YALNIZ bu qutudadır: cədvəl 9 sütunludur və telefonda səhifənin
         özünü üfüqi sürüşdürsəydi, başlıq və menyu da kənara çıxardı. --}}
    <div class="overflow-x-auto rounded-ds-xl border border-black/8 bg-card">
        <table class="w-full min-w-[880px] text-body">
            <thead>
                <tr class="border-b border-black/8 text-left text-helper font-semibold text-black/60">
                    <th class="px-5 py-3.5">{{ t('portal.estimate_work_type') }}</th>
                    <th class="px-5 py-3.5">{{ t('portal.estimate_room') }}</th>
                    <th class="px-5 py-3.5">{{ t('portal.estimate_unit') }}</th>
                    <th class="px-5 py-3.5 text-right">{{ t('portal.estimate_volume') }}</th>
                    <th class="px-5 py-3.5 text-right">{{ t('portal.estimate_work_price') }}</th>
                    <th class="px-5 py-3.5 text-right">{{ t('portal.estimate_material_price') }}</th>
                    <th class="px-5 py-3.5 text-right">{{ t('portal.estimate_line_total') }}</th>
                    <th class="px-5 py-3.5">{{ t('portal.status') }}</th>
                    <th class="px-5 py-3.5">{{ t('portal.nav_approvals') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    <tr class="border-b border-black/5 last:border-0">
                        <td class="px-5 py-4 font-medium">{{ $line->work_type }}</td>
                        <td class="px-5 py-4 text-black/70">{{ $line->room ?: '—' }}</td>
                        <td class="px-5 py-4 text-black/70">{{ $line->unit ?: '—' }}</td>
                        <td class="px-5 py-4 text-right text-black/70">{{ number_format((float) $line->qty, 2, '.', ' ') }}</td>
                        <td class="px-5 py-4 text-right text-black/70">{{ number_format((float) $line->work_price, 2, '.', ' ') }} ₼</td>
                        <td class="px-5 py-4 text-right text-black/70">{{ number_format((float) $line->material_price, 2, '.', ' ') }} ₼</td>
                        <td class="px-5 py-4 text-right font-semibold">{{ number_format((float) $line->total, 2, '.', ' ') }} ₼</td>
                        <td class="px-5 py-4 text-black/70">{{ $line->stage?->status?->label() ?? '—' }}</td>
                        <td class="px-5 py-4">
                            @if ($line->approval_status)
                                <span @class([
                                    'rounded-pill px-3 py-1 text-helper font-medium',
                                    'bg-ok-soft text-ok' => $line->approval_status === \App\Enums\ApprovalStatus::Approved,
                                    'bg-warn-soft text-warn' => $line->approval_status === \App\Enums\ApprovalStatus::Pending,
                                    'bg-error-soft text-error' => $line->approval_status === \App\Enums\ApprovalStatus::Rejected,
                                    'bg-neutral-soft text-black/60' => $line->approval_status === \App\Enums\ApprovalStatus::Draft,
                                ])>{{ $line->approval_status->translatedLabel() }}</span>
                            @else
                                <span class="text-black/45">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-5 py-12 text-center text-black/55">{{ t('portal.no_estimate') }}</td></tr>
                @endforelse
            </tbody>

            @if ($lines->isNotEmpty())
                <tfoot>
                    <tr class="border-t border-black/8 bg-gray-soft2/60">
                        <td colspan="6" class="px-5 py-4 text-right text-helper font-semibold text-black/60">
                            {{ t('portal.estimate_total') }}
                        </td>
                        <td class="px-5 py-4 text-right text-title font-semibold">
                            {{ number_format($total, 2, '.', ' ') }} ₼
                        </td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</x-portal.shell>
