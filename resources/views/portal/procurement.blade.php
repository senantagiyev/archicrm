<x-portal.shell :title="t('portal.nav_procurement')" :project="$project" active="procurement">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-heading font-semibold">{{ t('portal.nav_procurement') }}</h1>

        {{-- Roomix-də «Download Excel» cədvəlin ÜSTÜNDƏDİR — müştəri uzun
             siyahının sonuna sürüşmədən faylı götürə bilsin. Bizdə CSV-dir
             (Excel onu birbaşa açır), ona görə etiket də «CSV»dir. --}}
        <a href="{{ route('portal.procurement.export', $project) }}"
           class="ui-btn inline-flex items-center gap-2 rounded-ds-lg bg-accent-dark px-4 py-2.5 text-helper font-semibold text-white transition-colors hover:bg-accent-dark/85">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3v12M7 11l5 5 5-5M4 20h16"/>
            </svg>
            {{ t('portal.procurement_download') }}
        </a>
    </div>

    {{-- Sürüşmə YALNIZ bu qutudadır: cədvəl geniş sütunludur və telefonda səhifənin
         özünü üfüqi sürüşdürsəydi, başlıq və menyu da kənara çıxardı.
         Spesifikasiya ilə birləşmədən gələn sahələr (brend, model, xüsusiyyətlər,
         seçim statusu, əlavələr) müştəriyə açıqdır; təchizatçı mayası
         (`supplier_cost`) və daxili ləğv səbəbi heç vaxt göstərilmir. --}}
    {{-- Boş siyahıda 2400px-lik boş cədvəl göstərilmir: mesaj sağa sürüşüb
         görünmürdü (smoke test, 2026-10-15). --}}
    @if ($items->isEmpty())
        <div class="rounded-ds-xl border border-black/8 bg-card px-4 py-12 text-center text-black/55">{{ t('portal.no_procurement') }}</div>
    @else
    <div class="overflow-x-auto rounded-ds-xl border border-black/8 bg-card">
        <table class="w-full min-w-[2400px] text-body">
            <thead>
                <tr class="border-b border-black/8 text-left text-helper font-semibold text-black/60">
                    <th class="px-4 py-3.5">{{ t('portal.procurement_photo') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_name') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_brand') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_model') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_characteristics') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_analog') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_category') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.estimate_room') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.estimate_unit') }}</th>
                    <th class="px-4 py-3.5 text-right">{{ t('portal.procurement_qty') }}</th>
                    <th class="px-4 py-3.5 text-right">{{ t('portal.procurement_unit_price') }}</th>
                    <th class="px-4 py-3.5 text-right">{{ t('portal.estimate_line_total') }}</th>
                    <th class="px-4 py-3.5 text-right">{{ t('portal.procurement_discount') }}</th>
                    <th class="px-4 py-3.5 text-right">{{ t('portal.procurement_with_discount') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_availability') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_selection') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.nav_approvals') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_bought') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_delivery') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_link') }}</th>
                    <th class="px-4 py-3.5 text-right">{{ t('portal.procurement_delivery_assembly') }}</th>
                    <th class="px-4 py-3.5 text-right">{{ t('portal.procurement_reserve') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_comment') }}</th>
                    <th class="px-4 py-3.5">{{ t('portal.procurement_attachments') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr id="item-{{ $item->id }}" class="scroll-mt-24 border-b border-black/5 last:border-0">
                        <td class="px-4 py-4">
                            @if ($item->photo_path)
                                {{-- Foto birbaşa `public` disk linki ilə deyil, avtorizasiyalı
                                     marşrutla verilir: disk linki sessiya tələb etmir və onu
                                     bilən kənar şəxs müştərinin pozisiyasını görə bilərdi. --}}
                                @php $src = route('portal.procurement.photo', [$project->id, $item->id]); @endphp
                                <a href="{{ $src }}" target="_blank" rel="noopener"
                                   class="block h-12 w-12 overflow-hidden rounded-ds-lg border border-black/8 bg-neutral-soft">
                                    <img src="{{ $src }}" alt="" loading="lazy" class="h-12 w-12 object-cover">
                                </a>
                            @else
                                <span class="text-black/45">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-4 font-medium">{{ $item->name }}</td>
                        <td class="px-4 py-4 text-black/70">{{ $item->brand ?: '—' }}</td>
                        <td class="px-4 py-4 text-black/70">{{ $item->model ?: '—' }}</td>
                        <td class="max-w-[260px] whitespace-pre-line px-4 py-4 text-black/70">{{ $item->characteristics ?: '—' }}</td>
                        <td class="px-4 py-4 text-black/70">{{ $item->analog ?: '—' }}</td>
                        <td class="px-4 py-4 text-black/70">{{ $item->category ?: '—' }}</td>
                        <td class="px-4 py-4 text-black/70">{{ $item->room ?: '—' }}</td>
                        <td class="px-4 py-4 text-black/70">{{ $item->unit ?: '—' }}</td>
                        <td class="px-4 py-4 text-right text-black/70">{{ number_format((float) $item->qty, 2, '.', ' ') }}</td>
                        <td class="px-4 py-4 text-right text-black/70">{{ number_format((float) $item->price, 2, '.', ' ') }} ₼</td>
                        <td class="px-4 py-4 text-right font-semibold">{{ number_format((float) $item->total, 2, '.', ' ') }} ₼</td>
                        <td class="px-4 py-4 text-right text-black/70">
                            {{ $item->discount_percent === null ? '—' : number_format((float) $item->discount_percent, 2, '.', ' ').'%' }}
                        </td>
                        <td class="px-4 py-4 text-right font-semibold">{{ number_format($item->totalWithDiscount(), 2, '.', ' ') }} ₼</td>
                        <td class="px-4 py-4 text-black/70">{{ $item->availability ?: '—' }}</td>
                        <td class="px-4 py-4">
                            @if ($item->selection_status)
                                @php
                                    $selectionKey = 'enums.selection_status.'.$item->selection_status->value;
                                    $selectionLabel = t($selectionKey);
                                @endphp
                                <span class="rounded-pill bg-neutral-soft px-3 py-1 text-helper font-medium text-black/70">
                                    {{ is_string($selectionLabel) && $selectionLabel !== $selectionKey ? $selectionLabel : $item->selection_status->label() }}
                                </span>
                            @else
                                <span class="text-black/45">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-4">
                            @if ($item->approval_status)
                                <span @class([
                                    'rounded-pill px-3 py-1 text-helper font-medium',
                                    'bg-ok-soft text-ok' => $item->approval_status === \App\Enums\ApprovalStatus::Approved,
                                    'bg-warn-soft text-warn' => $item->approval_status === \App\Enums\ApprovalStatus::Pending,
                                    'bg-error-soft text-error' => $item->approval_status === \App\Enums\ApprovalStatus::Rejected,
                                    'bg-neutral-soft text-black/60' => $item->approval_status === \App\Enums\ApprovalStatus::Draft,
                                ])>{{ $item->approval_status->translatedLabel() }}</span>
                                @if ($item->approval_status === \App\Enums\ApprovalStatus::Pending)
                                    {{-- Qərar razılaşdırma kartında verilir (variant/şərh qaydaları orada). --}}
                                    <a href="{{ route('portal.approvals', $project) }}" class="mt-1.5 block text-helper font-semibold underline underline-offset-2">{{ t('portal.procurement_decide') }} →</a>
                                @endif
                            @else
                                <span class="text-black/45">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-4 text-black/70">{{ $item->paid ? t('portal.yes') : t('portal.no') }}</td>
                        <td class="px-4 py-4 text-black/70">{{ $item->delivery_date?->format('d.m.Y') ?? '—' }}</td>
                        <td class="px-4 py-4">
                            @if ($item->url)
                                {{-- `rel="noopener nofollow"`: link mağazanın saytına gedir,
                                     yəni bizim nəzarətimizdə olmayan kənar resursdur. --}}
                                <a href="{{ $item->url }}" target="_blank" rel="noopener nofollow"
                                   class="underline underline-offset-2">{{ $item->store ?: t('portal.procurement_link') }}</a>
                            @else
                                <span class="text-black/45">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-4 text-right text-black/70">{{ number_format((float) $item->delivery_assembly_price, 2, '.', ' ') }} ₼</td>
                        <td class="px-4 py-4 text-right text-black/70">{{ number_format((float) $item->reserve_percent, 2, '.', ' ') }}%</td>
                        <td class="px-4 py-4 text-black/70">{{ $item->comment ?: '—' }}</td>
                        <td class="px-4 py-4">
                            {{-- Şəkillər və texniki sənədlər pozisiyanın özündədir —
                                 avtorizasiyalı marşrutla açılır (public disk linki yox). --}}
                            @php $attachments = \App\Http\Controllers\Portal\ProcurementController::attachmentPaths($item); @endphp
                            @forelse ($attachments as $i => $path)
                                <a href="{{ route('portal.procurement.attachment', [$project->id, $item->id, $i]) }}" target="_blank" rel="noopener"
                                   class="block whitespace-nowrap text-helper underline underline-offset-2">{{ t('portal.procurement_file', ['n' => $i + 1]) }}@if (pathinfo($path, PATHINFO_EXTENSION)) ({{ strtoupper(pathinfo($path, PATHINFO_EXTENSION)) }})@endif</a>
                            @empty
                                <span class="text-black/45">—</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="25" class="px-4 py-12 text-center text-black/55">{{ t('portal.no_procurement') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif

    @if ($items->isNotEmpty())
        {{-- Roomix-də yekunlar cədvəlin ALTINDADIR. Cədvəl üfüqi sürüşdüyü üçün
             onları `tfoot`-a yox, ayrıca bloka qoyuruq: əks halda müştəri cəmi
             görmək üçün 19 sütunu sonadək sürüşdürməli olardı. --}}
        <div class="mt-4 flex flex-col items-end gap-2 rounded-ds-xl border border-black/8 bg-gray-soft2/60 px-5 py-4">
            <div class="flex w-full max-w-sm items-center justify-between gap-4">
                <span class="text-helper font-semibold text-black/60">{{ t('portal.procurement_total_before') }}</span>
                <span class="text-body">{{ number_format($totalBeforeDiscount, 2, '.', ' ') }} ₼</span>
            </div>
            <div class="flex w-full max-w-sm items-center justify-between gap-4">
                <span class="text-helper font-semibold text-black/60">{{ t('portal.procurement_total_after') }}</span>
                <span class="text-title font-semibold">{{ number_format($totalWithDiscount, 2, '.', ' ') }} ₼</span>
            </div>
        </div>
    @endif
</x-portal.shell>
