<?php if (isset($component)) { $__componentOriginal56a38354c4298987f2e55e4e4a4542c9 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal56a38354c4298987f2e55e4e4a4542c9 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.portal.shell','data' => ['title' => t('portal.nav_stages'),'project' => $project,'active' => 'stages']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('portal.shell'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(t('portal.nav_stages')),'project' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($project),'active' => 'stages']); ?>
    <?php
        // Roomix mərhələni rəngli status çipi ilə göstərir; Archi palitrasında
        // eyni məntiq: bitmiş — yaşıl, işdə — sarı vurğu, gecikmiş — qırmızı.
        // Enum tam adı ilə yazılır: `use` ifadəsi komponent slotunun içində
        // PHP səviyyəsində qanunsuzdur.
        $chip = fn ($s) => match ($s) {
            \App\Enums\StageStatus::Done => 'bg-ok-soft text-ok',
            \App\Enums\StageStatus::Overdue => 'bg-error-soft text-error',
            \App\Enums\StageStatus::InProgress, \App\Enums\StageStatus::Review => 'bg-sel-bg text-ink',
            default => 'bg-neutral-soft text-black/55',
        };
    ?>

    <div class="mb-5">
        <h1 class="text-heading font-semibold"><?php echo e(t('portal.nav_stages')); ?></h1>
        <p class="mt-1 text-helper text-black/55"><?php echo e(t('portal.stages_intro')); ?></p>
    </div>

    <div class="overflow-hidden rounded-ds-xl border border-black/8 bg-card">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $stages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $stage): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="flex flex-col gap-3 border-b border-black/5 px-5 py-4 last:border-0 sm:flex-row sm:items-center sm:gap-4">
                <span class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-helper font-semibold',
                    $chip($stage->status),
                ]); ?>">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stage->status === \App\Enums\StageStatus::Done): ?> ✓ <?php else: ?> <?php echo e($loop->iteration); ?> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-body font-semibold"><?php echo e($stage->name); ?></p>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stage->date_plan_end): ?>
                        <p class="mt-0.5 text-helper text-black/55"><?php echo e($stage->date_plan_end->format('d.m.Y')); ?></p>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>

                <span class="flex flex-wrap items-center gap-3 sm:shrink-0">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($stage->responsible): ?>
                        <span class="flex items-center gap-2 text-helper text-black/60">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-neutral-soft text-[11px] font-bold uppercase">
                                <?php echo e(mb_substr($stage->responsible->name, 0, 2)); ?>

                            </span>
                            <?php echo e($stage->responsible->name); ?>

                        </span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <span class="<?php echo \Illuminate\Support\Arr::toCssClasses(['rounded-pill px-3 py-1 text-helper font-medium', $chip($stage->status)]); ?>">
                        <?php echo e($stage->status->label()); ?>

                    </span>
                </span>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <p class="px-5 py-12 text-center text-body text-black/55"><?php echo e(t('portal.no_stages')); ?></p>
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
<?php /**PATH C:\Users\User\Herd\ArchiCRM\resources\views/portal/stages.blade.php ENDPATH**/ ?>