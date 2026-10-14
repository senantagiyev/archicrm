{{-- Dəyişiklik sorğusu razılaşdırmasının məzmunu: müştəri NƏYƏ razılıq verdiyini
     görməlidir — nə dəyişir, niyə, qiymətə və müddətə təsiri. Kartın başlığı
     yalnız adı göstərir; rəqəmlər olmadan «Təsdiqlə» düyməsi boş razılıq olardı. --}}

@php
    $compact ??= false;
    $cost = (float) $changeRequest->cost_impact;
    $days = (int) $changeRequest->schedule_impact_days;
@endphp

<div class="mt-2 space-y-1.5">
    @if (filled($changeRequest->description))
        <p class="{{ $compact ? 'line-clamp-3 text-[14px]' : 'text-body' }} whitespace-pre-line text-black/70">{{ $changeRequest->description }}</p>
    @endif
    @if (filled($changeRequest->reason) && ! $compact)
        <p class="text-helper text-black/55"><span class="font-semibold">{{ t('portal.cr_reason') }}:</span> {{ $changeRequest->reason }}</p>
    @endif
    <div class="flex flex-wrap gap-2 pt-1" data-cr-impact>
        <span class="rounded-pill bg-neutral-soft px-3 py-1 text-[13px]">
            {{ t('portal.cr_cost_impact') }}:
            <strong>{{ $cost == 0 ? t('portal.cr_no_change') : ($cost > 0 ? '+' : '−').number_format(abs($cost), 2, '.', ' ').' ₼' }}</strong>
        </span>
        <span class="rounded-pill bg-neutral-soft px-3 py-1 text-[13px]">
            {{ t('portal.cr_schedule_impact') }}:
            <strong>{{ $days === 0 ? t('portal.cr_no_change') : ($days > 0 ? '+' : '−').t('portal.cr_days', ['days' => abs($days)]) }}</strong>
        </span>
    </div>
</div>
