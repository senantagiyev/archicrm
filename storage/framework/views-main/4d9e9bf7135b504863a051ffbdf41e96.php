<?php if (isset($component)) { $__componentOriginal56a38354c4298987f2e55e4e4a4542c9 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal56a38354c4298987f2e55e4e4a4542c9 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.portal.shell','data' => ['title' => t('portal.nav_diary'),'project' => $project,'active' => 'diary']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('portal.shell'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(t('portal.nav_diary')),'project' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($project),'active' => 'diary']); ?>
    <div class="mb-5">
        <h1 class="text-heading font-semibold"><?php echo e(t('portal.nav_diary')); ?></h1>
        <p class="mt-1 text-helper text-black/55"><?php echo e(t('portal.diary_intro')); ?></p>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $entries; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $entry): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
        <?php
            // Fotolar Filament-in `public` diskinə yüklənir; portalda ayrıca
            // yükləmə marşrutu yoxdur, ona görə birbaşa disk URL-i ilə göstərilir.
            $photos = collect($entry->photos ?? [])->filter()->values();
        ?>

        <article class="mb-4 rounded-ds-xl border border-black/8 bg-card px-5 py-4 last:mb-0">
            <div class="mb-2 flex flex-wrap items-center gap-2 text-helper text-black/55">
                <span class="font-semibold text-black/70"><?php echo e($entry->published_at->format('d.m.Y H:i')); ?></span>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($entry->author): ?>
                    <span aria-hidden="true">·</span>
                    <span><?php echo e($entry->author->name); ?></span>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>

            <p class="whitespace-pre-line text-body"><?php echo e($entry->body); ?></p>

            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($photos->isNotEmpty()): ?>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $photos; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $photo): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php $src = route('portal.diary.photo', [$project->id, $entry->id, $i]); ?>
                        <a href="<?php echo e($src); ?>" target="_blank" rel="noopener"
                           class="block overflow-hidden rounded-ds-lg border border-black/8 bg-neutral-soft">
                            <img src="<?php echo e($src); ?>" alt="" loading="lazy" class="h-32 w-full object-cover">
                        </a>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </article>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
        <div class="rounded-ds-xl border border-black/8 bg-card px-5 py-12 text-center">
            <p class="text-body text-black/55"><?php echo e(t('portal.no_diary')); ?></p>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
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
<?php /**PATH C:\Users\User\Herd\ArchiCRM\resources\views/portal/diary.blade.php ENDPATH**/ ?>