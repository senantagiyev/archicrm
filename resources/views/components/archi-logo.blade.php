@props([
    // dark    — tünd mətn (açıq fon üzərində)
    // light   — sabit ağ mətn (həmişə tünd qalan fon üzərində)
    // sidebar — sol panelin öz tokeni; temaya görə çevrilir, çünki panel artıq
    //           açıq temada ağdır və orada sabit ağ loqo görünməzdi
    'variant' => 'dark',
    'sub' => true,          // show "architecture bureau" caption
])
@php
    $ink = match ($variant) {
        'light' => '#ffffff',
        'sidebar' => 'var(--color-sidebar-ink)',
        default => '#111111',
    };
    $subColor = match ($variant) {
        'light' => 'text-white/45',
        'sidebar' => 'text-sidebar-ink/45',
        default => 'text-black/40',
    };
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5 select-none']) }} aria-label="ARCHI architecture bureau">
    <svg width="26" height="26" viewBox="0 0 28 28" aria-hidden="true" class="shrink-0">
        <rect width="28" height="28" rx="7" fill="#fdfe00"/>
        <path d="M8.4 20.6 14 7.6l5.6 13" fill="none" stroke="#111111" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M10.9 15.7h6.2" stroke="#111111" stroke-width="2.4" stroke-linecap="round"/>
    </svg>
    <span class="flex flex-col leading-none">
        <span class="font-b2b text-[19px] font-extrabold tracking-[0.14em]" style="color: {{ $ink }}">ARCHI</span>
        @if ($sub)
            <span class="mt-1 text-[9px] font-medium uppercase tracking-[0.22em] {{ $subColor }}">architecture bureau</span>
        @endif
    </span>
</span>
