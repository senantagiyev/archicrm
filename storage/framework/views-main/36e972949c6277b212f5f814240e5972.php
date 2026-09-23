<?php if (isset($component)) { $__componentOriginal56a38354c4298987f2e55e4e4a4542c9 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal56a38354c4298987f2e55e4e4a4542c9 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.portal.shell','data' => ['title' => t('portal.nav_files'),'project' => $project,'active' => 'files']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('portal.shell'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(t('portal.nav_files')),'project' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($project),'active' => 'files']); ?>
    <?php
        // Roomix-dəki çip sırası. `all` həmişə birincidir ki, müştəri filtrdən
        // asanlıqla geri qayıtsın. Enum tam adı ilə yazılır: `use` ifadəsi
        // komponent slotunun içində PHP səviyyəsində qanunsuzdur.
        $chips = [
            'all' => [t('portal.files_all'), null],
            'media' => [t('portal.files_media'), $counts['media'] ?? 0],
            'files' => [t('portal.files_files'), $counts['files'] ?? 0],
            'links' => [t('portal.files_links'), $counts['links'] ?? 0],
            'docs' => [t('portal.files_docs'), $counts['docs'] ?? 0],
        ];

        $icons = [
            'image' => 'M3 3h18v18H3zM3 15l5-5 4 4 3-3 6 6',
            'visualization' => 'M3 3h18v18H3zM3 15l5-5 4 4 3-3 6 6',
            'plan' => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2ZM15 2v5h5',
            'link' => 'M10 13a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 11a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1',
            'other' => 'M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Z',
        ];
    ?>

    <h1 class="mb-4 text-heading font-semibold"><?php echo e(t('portal.nav_files')); ?></h1>

    <div class="mb-5 flex flex-wrap gap-2">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $chips; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => [$label, $count]): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php $on = $filter === $key; ?>
            <a href="<?php echo e($key === 'all' ? route('portal.files', $project) : route('portal.files', [$project, 'filter' => $key])); ?>"
               <?php if($on): ?> aria-current="page" <?php endif; ?>
               class="flex items-center gap-1.5 rounded-pill border px-3.5 py-1.5 text-helper font-medium transition-colors
                      <?php echo e($on ? 'border-ink bg-accent-dark text-white' : 'border-black/15 bg-card hover:border-black/35'); ?>">
                <?php echo e($label); ?>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($count !== null && $count > 0): ?>
                    <span class="text-[11px] font-bold <?php echo e($on ? 'text-white/70' : 'text-black/45'); ?>"><?php echo e($count); ?></span>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </a>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <div class="overflow-hidden rounded-ds-xl border border-black/8 bg-card">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $files; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $file): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <a href="<?php echo e(route('portal.files.download', [$project, $file])); ?>"
               class="group flex items-center gap-4 border-b border-black/5 px-5 py-4 transition-colors last:border-0 hover:bg-neutral-soft/50">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-ds-lg bg-neutral-soft text-ink">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="<?php echo e($icons[$file->category->value] ?? $icons['other']); ?>"/>
                    </svg>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-body font-semibold"><?php echo e($file->title); ?></p>
                    <p class="mt-1 text-helper text-black/55">
                        <?php echo e($file->category->label()); ?> · <?php echo e($file->created_at->format('d.m.Y')); ?>

                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($file->size): ?> · <?php echo e(number_format($file->size / 1024, 0, '.', ' ')); ?> KB <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </p>
                </div>

                <span class="shrink-0 text-black/45 transition-colors group-hover:text-ink" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 19h16"/></svg>
                </span>
            </a>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <p class="px-5 py-12 text-center text-body text-black/55"><?php echo e(t('portal.no_files')); ?></p>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal56a38354c4298987f2e55e4e4a4542c9)): ?>
<?php $attributes = $__attributesOriginal56a38354c4298987f2e55e4e4a4542c9; ?>
<?php unset($__attributesOriginal56a38354c4298987f2e55e4e4a4542c9); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal56a38354c4298987f2e55e4e4a4542c9)): ?>
<?php $component = $__componentOriginal56a38354c4298987f2e55e4e4a4542c9; ?>
<?php unset($__componentOriginal56a38354c4298987f2e55e4e4a4542c9); ?>
<?php endif; ?>
<?php /**PATH C:\Users\User\Herd\ArchiCRM\resources\views/portal/files.blade.php ENDPATH**/ ?>