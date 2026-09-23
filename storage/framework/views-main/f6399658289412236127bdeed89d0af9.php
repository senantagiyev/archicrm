<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['title' => null, 'project' => null, 'active' => null]));

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

foreach (array_filter((['title' => null, 'project' => null, 'active' => null]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    // Roomix modeli: SOL panel QLOBAL naviqasiyadır (layihədən asılı deyil),
    // layihə bölmələri isə layihə başlığının altındakı YUXARI TAB-lara düşür.
    // Əvvəl hər şey sol paneldə idi — o zaman istifadəçi hansı layihənin
    // içində olduğunu yalnız kiçik mətn etiketindən bilirdi.
    $nav = [
        'projects'  => [route('portal.home'),           t('portal.my_projects'),   'M4 20V8l8-5 8 5v12M9 20v-6h6v6'],
        'approvals' => [route('portal.approvals.all'),  t('portal.nav_approvals'), 'M22 11.1V12a10 10 0 1 1-5.9-9.1M22 4 12 14l-3-3'],
        'documents' => [route('portal.documents.all'),  t('portal.nav_documents'), 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z'],
        'notifications' => [route('portal.notifications'), t('portal.nav_notifications'), 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0'],
        'profile'   => [route('portal.profile'),        t('portal.nav_profile'),   'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM4.5 21a7.5 7.5 0 0 1 15 0'],
    ];

    $tabs = $project ? [
        'overview'  => [route('portal.projects.show', $project), t('portal.nav_overview'),  'M3 11l9-8 9 8M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5'],
        'brief'     => [route('portal.brief', $project),         t('portal.nav_brief'),     'M8 4h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2M9.5 9h5M9.5 13h5M9.5 17h3'],
        'chat'      => [route('portal.chat', $project),          t('portal.nav_chat'),      'M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5'],
        'stages'    => [route('portal.stages', $project),        t('portal.nav_stages'),    'M4 6h16M4 12h16M4 18h9M2.5 6h.01M2.5 12h.01M2.5 18h.01'],
        'files'     => [route('portal.files', $project),         t('portal.nav_files'),     'M4 5a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5Z'],
        'diary'     => [route('portal.diary', $project),         t('portal.nav_diary'),     'M4 4h13l3 3v13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1ZM7 10h9M7 14h6'],
        // Razılaşdırma, smeta və komplektasiya QƏSDƏN tab deyil: Roomix-də onlar
        // sənəd alt-səhifələridir (`/estimate/<id>`, `/complectation/<id>`), tab
        // zolağında isə cəmi 4 bənd var. Onları da tab etsək zolaq 11 bəndə
        // çatıb daşırdı — indi «Sənədlər» səhifəsindən açılırlar.
        'documents' => [route('portal.documents', $project),     t('portal.nav_documents'), 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z'],
        'payments'  => [route('portal.payments', $project),      t('portal.nav_payments'),  'M3 10h18M3 6h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1ZM7 15h2'],
    ] : [];

    // Sol panel qlobal naviqasiyadır: layihənin İÇİNDƏ olanda orada «Layihələrim»
    // işıqlanır (Roomix-də də belədir), konkret bölmə isə yuxarı tabda vurğulanır.
    $navActive = $project ? 'projects' : ($active ?? 'projects');
    $tabActive = $active;

    // Tab bar, səhifə başlığı və <main> EYNİ konteynerdən keçir — əks halda geniş
    // ekranda tablar sol kənardan, məzmun isə mərkəzdən başlayırdı.
    $container = 'mx-auto w-full max-w-[1180px] px-5 lg:px-8';
?>

<!DOCTYPE html>
<html lang="<?php echo e(app()->getLocale()); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($title ? $title.' — ' : ''); ?>ARCHI</title>
    
    <script>
        (() => {
            try {
                const saved = localStorage.getItem('archi-theme');
                const dark = saved ? saved === 'dark'
                    : window.matchMedia('(prefers-color-scheme: dark)').matches;
                if (dark) document.documentElement.dataset.theme = 'dark';
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <?php echo app('Illuminate\Foundation\Vite')('resources/css/app.css'); ?>
</head>
<body class="min-h-screen bg-gray-soft2 text-ink">

    
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-[240px] flex-col border-r border-black/8 bg-sidebar text-sidebar-ink lg:flex">
        <div class="px-6 pb-5 pt-6">
            <a href="<?php echo e(route('portal.home')); ?>"><?php if (isset($component)) { $__componentOriginal619871369e916f751c675bab3a95f6a9 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal619871369e916f751c675bab3a95f6a9 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.archi-logo','data' => ['variant' => 'sidebar']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('archi-logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'sidebar']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal619871369e916f751c675bab3a95f6a9)): ?>
<?php $attributes = $__attributesOriginal619871369e916f751c675bab3a95f6a9; ?>
<?php unset($__attributesOriginal619871369e916f751c675bab3a95f6a9); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal619871369e916f751c675bab3a95f6a9)): ?>
<?php $component = $__componentOriginal619871369e916f751c675bab3a95f6a9; ?>
<?php unset($__componentOriginal619871369e916f751c675bab3a95f6a9); ?>
<?php endif; ?></a>
        </div>

        
        <nav class="flex-1 space-y-1 px-3">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $nav; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => [$url, $label, $icon]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <a href="<?php echo e($url); ?>"
                   <?php if($navActive === $key): ?> aria-current="page" <?php endif; ?>
                   class="relative flex items-center gap-3 rounded-[12px] px-4 py-2.5 text-[16px] transition-colors
                          <?php echo e($navActive === $key ? 'bg-yellow font-semibold text-on-yellow' : 'font-medium text-sidebar-ink/70 hover:bg-sidebar-ink/8 hover:text-sidebar-ink'); ?>">
                    <svg width="20" height="20" class="shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?php echo e($icon); ?>"/></svg>
                    <span class="truncate"><?php echo e($label); ?></span>
                </a>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </nav>

        <div class="border-t border-sidebar-ink/10 px-3 py-4">
            <form method="post" action="<?php echo e(route('locale.switch')); ?>" class="mb-3 flex items-center gap-1 px-4 text-[13px] font-semibold uppercase">
                <?php echo csrf_field(); ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['az', 'ru', 'en']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $loc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <button name="locale" value="<?php echo e($loc); ?>"
                        class="rounded-ds px-2 py-1 <?php echo e(app()->getLocale() === $loc ? 'bg-sidebar-ink/15 text-sidebar-ink' : 'text-sidebar-ink/45 hover:text-sidebar-ink'); ?>">
                        <?php echo e(strtoupper($loc)); ?>

                    </button>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </form>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard('customer')->check()): ?>
                <form method="post" action="<?php echo e(route('portal.logout')); ?>">
                    <?php echo csrf_field(); ?>
                    <button class="flex w-full items-center gap-3 rounded-[12px] px-4 py-2.5 text-[16px] font-medium text-sidebar-ink/70 transition-colors hover:bg-sidebar-ink/8 hover:text-sidebar-ink">
                        <svg width="20" height="20" class="shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                        <?php echo e(t('portal.logout')); ?>

                    </button>
                </form>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </aside>

    
    <header class="border-b border-black/8 bg-sidebar text-sidebar-ink lg:hidden">
        <div class="flex h-[60px] items-center justify-between px-4">
            <a href="<?php echo e(route('portal.home')); ?>"><?php if (isset($component)) { $__componentOriginal619871369e916f751c675bab3a95f6a9 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal619871369e916f751c675bab3a95f6a9 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.archi-logo','data' => ['variant' => 'sidebar','sub' => false]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('archi-logo'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'sidebar','sub' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(false)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal619871369e916f751c675bab3a95f6a9)): ?>
<?php $attributes = $__attributesOriginal619871369e916f751c675bab3a95f6a9; ?>
<?php unset($__attributesOriginal619871369e916f751c675bab3a95f6a9); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal619871369e916f751c675bab3a95f6a9)): ?>
<?php $component = $__componentOriginal619871369e916f751c675bab3a95f6a9; ?>
<?php unset($__componentOriginal619871369e916f751c675bab3a95f6a9); ?>
<?php endif; ?></a>
            <div class="flex items-center gap-2">
                <form method="post" action="<?php echo e(route('locale.switch')); ?>" class="flex items-center gap-1 text-[11px] font-bold uppercase">
                    <?php echo csrf_field(); ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ['az', 'ru', 'en']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $loc): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <button name="locale" value="<?php echo e($loc); ?>"
                            class="rounded-ds px-1.5 py-1 <?php echo e(app()->getLocale() === $loc ? 'bg-yellow text-on-yellow' : 'text-sidebar-ink/50'); ?>"><?php echo e(strtoupper($loc)); ?></button>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </form>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard('customer')->check()): ?>
                    <form method="post" action="<?php echo e(route('portal.logout')); ?>">
                        <?php echo csrf_field(); ?>
                        <button class="ui-btn ui-btn-on-sidebar h-8 px-3 text-[12px] font-semibold" data-hover="true"><?php echo e(t('portal.logout')); ?></button>
                    </form>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
        
        <nav class="flex items-center gap-1 overflow-x-auto border-t border-sidebar-ink/10 px-2 py-2">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = ($project ? $tabs : $nav); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => [$url, $label, $icon]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <a href="<?php echo e($url); ?>" <?php if($key === 'chat'): ?> data-nav-chat <?php endif; ?>
                   class="relative whitespace-nowrap rounded-[10px] px-3 py-1.5 text-[14px] font-semibold
                          <?php echo e(($project ? $tabActive : $navActive) === $key ? 'bg-yellow text-on-yellow' : 'text-sidebar-ink/65'); ?>">
                    <?php echo e($label); ?>

                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($key === 'chat'): ?>
                        <span data-chat-dot hidden class="absolute -right-0.5 -top-0.5 inline-block h-2 w-2 rounded-pill bg-danger"></span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </a>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </nav>
    </header>

    
    <div class="lg:pl-[240px]">
        
        <div class="hidden border-b border-black/8 bg-card lg:block">
            <div class="<?php echo e($container); ?> flex h-[64px] items-center gap-4">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($project): ?>
                    <a href="<?php echo e(route('portal.home')); ?>" aria-label="<?php echo e(t('portal.my_projects')); ?>"
                       class="-ml-2 flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] text-ink/60 transition-colors hover:bg-neutral-soft hover:text-ink">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                    </a>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                <p class="min-w-0 truncate text-[15px] font-bold"><?php echo e($project?->name ?? ($title ?? t('portal.my_projects'))); ?></p>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($project): ?>
                    
                    <?php $waiting = $project->pendingClientApprovalsCount(); ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($waiting > 0): ?>
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-pill bg-yellow px-3 py-1 text-[13px] font-bold text-on-yellow">
                            <?php echo e(t('portal.project_your_turn')); ?>

                            <span class="text-ink/60"><?php echo e($waiting); ?></span>
                        </span>
                    <?php else: ?>
                        <span class="inline-flex shrink-0 items-center gap-2 text-[13px] font-semibold text-black/45">
                            <span class="h-2 w-2 rounded-full bg-yellow"></span><?php echo e($project->status->label()); ?>

                        </span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

                <span class="flex-1"></span>

                
                <button type="button" data-theme-toggle
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] text-ink/60 transition-colors hover:bg-neutral-soft hover:text-ink"
                        aria-label="<?php echo e(t('portal.theme_toggle')); ?>" title="<?php echo e(t('portal.theme_toggle')); ?>">
                    <svg data-theme-icon="light" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
                    </svg>
                    <svg data-theme-icon="dark" hidden width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>
                    </svg>
                </button>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard('customer')->check()): ?>
                    <a href="<?php echo e(route('portal.profile')); ?>" class="flex shrink-0 items-center gap-3 rounded-[10px] px-2 py-1 transition-colors hover:bg-neutral-soft">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-accent-dark text-[12px] font-bold text-white">
                            <?php echo e(mb_strtoupper(mb_substr(auth('customer')->user()->name, 0, 1))); ?>

                        </span>
                        <span class="text-[13px] font-semibold"><?php echo e(auth('customer')->user()->name); ?></span>
                    </a>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>

        
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($project): ?>
            <div class="hidden border-b border-black/8 bg-card lg:block">
                
                <nav class="<?php echo e($container); ?> flex items-stretch overflow-x-auto" aria-label="<?php echo e($project->name); ?>">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $tabs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => [$url, $label, $icon]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <a href="<?php echo e($url); ?>" <?php if($key === 'chat'): ?> data-nav-chat <?php endif; ?>
                           <?php if($tabActive === $key): ?> aria-current="page" <?php endif; ?>
                           class="flex min-w-fit flex-1 basis-0 flex-col items-center gap-1 border-b-2 px-3 py-2.5 text-[13px] font-semibold transition-colors
                                  <?php echo e($tabActive === $key
                                        ? 'border-yellow text-ink'
                                        : 'border-transparent text-black/55 hover:text-ink'); ?>">
                            
                            <span class="relative">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?php echo e($icon); ?>"/></svg>
                                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($key === 'chat'): ?>
                                    <span data-chat-dot hidden class="absolute -right-1 -top-0.5 inline-block h-2 w-2 rounded-pill bg-danger"></span>
                                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                            </span>
                            <span class="whitespace-nowrap"><?php echo e($label); ?></span>
                        </a>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </nav>
            </div>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <main class="<?php echo e($container); ?> py-7">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(session('status')): ?>
                <div class="mb-5 rounded-[12px] border border-ok/30 bg-ok-soft px-4 py-3 text-sm font-medium text-ok">
                    <?php echo e(session('status')); ?>

                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($errors->any()): ?>
                <div class="mb-5 rounded-[12px] border border-error/30 bg-error-soft px-4 py-3 text-sm font-medium text-error">
                    <?php echo e($errors->first()); ?>

                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

            <?php echo e($slot); ?>

        </main>

        <footer class="<?php echo e($container); ?> pb-8 pt-2 text-[13px] text-black/40">
            © <?php echo e(date('Y')); ?> ARCHI
        </footer>
    </div>

    <script>
        // Tema keçidi. Seçim `localStorage`-dadır, yəni serverə yazılmır və
        // cihaza bağlı qalır — istifadəçi telefonda tünd, masaüstündə açıq
        // işlədə bilir. Səhifə yüklənəndə vəziyyəti <head>-dəki skript qurur,
        // burada yalnız ikon sinxronlaşdırılır və klik emal olunur.
        (() => {
            const root = document.documentElement;
            const button = document.querySelector('[data-theme-toggle]');
            if (!button) return;

            const sync = () => {
                const dark = root.dataset.theme === 'dark';
                button.querySelector('[data-theme-icon="light"]').hidden = dark;
                button.querySelector('[data-theme-icon="dark"]').hidden = !dark;
                button.setAttribute('aria-pressed', dark ? 'true' : 'false');
            };

            button.addEventListener('click', () => {
                const dark = root.dataset.theme === 'dark';
                if (dark) {
                    delete root.dataset.theme;
                } else {
                    root.dataset.theme = 'dark';
                }
                try { localStorage.setItem('archi-theme', dark ? 'light' : 'dark'); } catch (e) {}
                sync();
            });

            sync();
        })();
    </script>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard('customer')->check()): ?>
    <script>
        // Global chat sound + unread dot for the customer, on every portal page.
        (() => {
            const url = <?php echo json_encode(route('portal.chat.unread'), 15, 512) ?>;
            let last = null;

            const beep = () => {
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    const play = (freq, start) => {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.connect(gain); gain.connect(ctx.destination);
                        osc.frequency.value = freq;
                        gain.gain.setValueAtTime(0.06, ctx.currentTime + start);
                        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + 0.3);
                        osc.start(ctx.currentTime + start); osc.stop(ctx.currentTime + start + 0.35);
                    };
                    play(880, 0); play(660, 0.18);
                } catch (e) {}
            };

            const check = () => {
                fetch(url, { headers: { Accept: 'application/json' } })
                    .then(r => r.json())
                    .then(d => {
                        document.querySelectorAll('[data-chat-dot]').forEach(el => el.hidden = !(d.count > 0));
                        if (last !== null && d.count > last) beep();
                        last = d.count;
                    })
                    .catch(() => {});
            };

            check();
            setInterval(check, 15000);
        })();
    </script>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</body>
</html>
<?php /**PATH C:\Users\User\Herd\ArchiCRM\resources\views/components/portal/shell.blade.php ENDPATH**/ ?>