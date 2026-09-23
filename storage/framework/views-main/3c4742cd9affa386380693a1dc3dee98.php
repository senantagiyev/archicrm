<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    // dark    — tünd mətn (açıq fon üzərində)
    // light   — sabit ağ mətn (həmişə tünd qalan fon üzərində)
    // sidebar — sol panelin öz tokeni; temaya görə çevrilir, çünki panel artıq
    //           açıq temada ağdır və orada sabit ağ loqo görünməzdi
    'variant' => 'dark',
    'sub' => true,          // show "architecture bureau" caption
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    // dark    — tünd mətn (açıq fon üzərində)
    // light   — sabit ağ mətn (həmişə tünd qalan fon üzərində)
    // sidebar — sol panelin öz tokeni; temaya görə çevrilir, çünki panel artıq
    //           açıq temada ağdır və orada sabit ağ loqo görünməzdi
    'variant' => 'dark',
    'sub' => true,          // show "architecture bureau" caption
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php
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
?>
<span <?php echo e($attributes->merge(['class' => 'inline-flex items-center gap-2.5 select-none'])); ?> aria-label="ARCHI architecture bureau">
    <svg width="26" height="26" viewBox="0 0 28 28" aria-hidden="true" class="shrink-0">
        <rect width="28" height="28" rx="7" fill="#fdfe00"/>
        <path d="M8.4 20.6 14 7.6l5.6 13" fill="none" stroke="#111111" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M10.9 15.7h6.2" stroke="#111111" stroke-width="2.4" stroke-linecap="round"/>
    </svg>
    <span class="flex flex-col leading-none">
        <span class="font-b2b text-[19px] font-extrabold tracking-[0.14em]" style="color: <?php echo e($ink); ?>">ARCHI</span>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($sub): ?>
            <span class="mt-1 text-[9px] font-medium uppercase tracking-[0.22em] <?php echo e($subColor); ?>">architecture bureau</span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </span>
</span>
<?php /**PATH C:\Users\User\Herd\ArchiCRM\resources\views/components/archi-logo.blade.php ENDPATH**/ ?>