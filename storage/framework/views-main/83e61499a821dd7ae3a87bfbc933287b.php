<?php if (isset($component)) { $__componentOriginal56a38354c4298987f2e55e4e4a4542c9 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal56a38354c4298987f2e55e4e4a4542c9 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.portal.shell','data' => ['title' => t('portal.nav_procurement'),'project' => $project,'active' => 'documents']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('portal.shell'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(t('portal.nav_procurement')),'project' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($project),'active' => 'documents']); ?>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-heading font-semibold"><?php echo e(t('portal.nav_procurement')); ?></h1>

        
        <a href="<?php echo e(route('portal.procurement.export', $project)); ?>"
           class="ui-btn inline-flex items-center gap-2 rounded-ds-lg bg-accent-dark px-4 py-2.5 text-helper font-semibold text-white transition-colors hover:bg-accent-dark/85">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3v12M7 11l5 5 5-5M4 20h16"/>
            </svg>
            <?php echo e(t('portal.procurement_download')); ?>

        </a>
    </div>

    
    <div class="overflow-x-auto rounded-ds-xl border border-black/8 bg-card">
        <table class="w-full min-w-[1800px] text-body">
            <thead>
                <tr class="border-b border-black/8 text-left text-helper font-semibold text-black/60">
                    <th class="px-4 py-3.5"><?php echo e(t('portal.procurement_photo')); ?></th>
                    <th class="px-4 py-3.5"><?php echo e(t('portal.procurement_name')); ?></th>
                    <th class="px-4 py-3.5"><?php echo e(t('portal.procurement_analog')); ?></th>
                    <th class="px-4 py-3.5"><?php echo e(t('portal.procurement_category')); ?></th>
                    <th class="px-4 py-3.5"><?php echo e(t('portal.estimate_room')); ?></th>
                    <th class="px-4 py-3.5"><?php echo e(t('portal.estimate_unit')); ?></th>
                    <th class="px-4 py-3.5 text-right"><?php echo e(t('portal.procurement_qty')); ?></th>
                    <th class="px-4 py-3.5 text-right"><?php echo e(t('portal.procurement_unit_price')); ?></th>
                    <th class="px-4 py-3.5 text-right"><?php echo e(t('portal.estimate_line_total')); ?></th>
                    <th class="px-4 py-3.5 text-right"><?php echo e(t('portal.procurement_discount')); ?></th>
                    <th class="px-4 py-3.5 text-right"><?php echo e(t('portal.procurement_with_discount')); ?></th>
                    <th class="px-4 py-3.5"><?php echo e(t('portal.procurement_availability')); ?></th>
                    <th class="px-4 py-3.5"><?php echo e(t('portal.nav_approvals')); ?></th>
                    <th class="px-4 py-3.5"><?php echo e(t('portal.procurement_bought')); ?></th>
                    <th class="px-4 py-3.5"><?php echo e(t('portal.procurement_delivery')); ?></th>
                    <th class="px-4 py-3.5"><?php echo e(t('portal.procurement_link')); ?></th>
                    <th class="px-4 py-3.5 text-right"><?php echo e(t('portal.procurement_delivery_assembly')); ?></th>
                    <th class="px-4 py-3.5 text-right"><?php echo e(t('portal.procurement_reserve')); ?></th>
                    <th class="px-4 py-3.5"><?php echo e(t('portal.procurement_comment')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr class="border-b border-black/5 last:border-0">
                        <td class="px-4 py-4">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->photo_path): ?>
                                
                                <?php $src = route('portal.procurement.photo', [$project->id, $item->id]); ?>
                                <a href="<?php echo e($src); ?>" target="_blank" rel="noopener"
                                   class="block h-12 w-12 overflow-hidden rounded-ds-lg border border-black/8 bg-neutral-soft">
                                    <img src="<?php echo e($src); ?>" alt="" loading="lazy" class="h-12 w-12 object-cover">
                                </a>
                            <?php else: ?>
                                <span class="text-black/45">—</span>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </td>
                        <td class="px-4 py-4 font-medium"><?php echo e($item->name); ?></td>
                        <td class="px-4 py-4 text-black/70"><?php echo e($item->analog ?: '—'); ?></td>
                        <td class="px-4 py-4 text-black/70"><?php echo e($item->category ?: '—'); ?></td>
                        <td class="px-4 py-4 text-black/70"><?php echo e($item->room ?: '—'); ?></td>
                        <td class="px-4 py-4 text-black/70"><?php echo e($item->unit ?: '—'); ?></td>
                        <td class="px-4 py-4 text-right text-black/70"><?php echo e(number_format((float) $item->qty, 2, '.', ' ')); ?></td>
                        <td class="px-4 py-4 text-right text-black/70"><?php echo e(number_format((float) $item->price, 2, '.', ' ')); ?> ₼</td>
                        <td class="px-4 py-4 text-right font-semibold"><?php echo e(number_format((float) $item->total, 2, '.', ' ')); ?> ₼</td>
                        <td class="px-4 py-4 text-right text-black/70">
                            <?php echo e($item->discount_percent === null ? '—' : number_format((float) $item->discount_percent, 2, '.', ' ').'%'); ?>

                        </td>
                        <td class="px-4 py-4 text-right font-semibold"><?php echo e(number_format($item->totalWithDiscount(), 2, '.', ' ')); ?> ₼</td>
                        <td class="px-4 py-4 text-black/70"><?php echo e($item->availability ?: '—'); ?></td>
                        <td class="px-4 py-4">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->approval_status): ?>
                                <span class="<?php echo \Illuminate\Support\Arr::toCssClasses([
                                    'rounded-pill px-3 py-1 text-helper font-medium',
                                    'bg-ok-soft text-ok' => $item->approval_status === \App\Enums\ApprovalStatus::Approved,
                                    'bg-warn-soft text-warn' => $item->approval_status === \App\Enums\ApprovalStatus::Pending,
                                    'bg-error-soft text-error' => $item->approval_status === \App\Enums\ApprovalStatus::Rejected,
                                    'bg-neutral-soft text-black/60' => $item->approval_status === \App\Enums\ApprovalStatus::Draft,
                                ]); ?>"><?php echo e($item->approval_status->translatedLabel()); ?></span>
                            <?php else: ?>
                                <span class="text-black/45">—</span>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </td>
                        <td class="px-4 py-4 text-black/70"><?php echo e($item->paid ? t('portal.yes') : t('portal.no')); ?></td>
                        <td class="px-4 py-4 text-black/70"><?php echo e($item->delivery_date?->format('d.m.Y') ?? '—'); ?></td>
                        <td class="px-4 py-4">
                            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($item->url): ?>
                                
                                <a href="<?php echo e($item->url); ?>" target="_blank" rel="noopener nofollow"
                                   class="underline underline-offset-2"><?php echo e($item->store ?: t('portal.procurement_link')); ?></a>
                            <?php else: ?>
                                <span class="text-black/45">—</span>
                            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                        </td>
                        <td class="px-4 py-4 text-right text-black/70"><?php echo e(number_format((float) $item->delivery_assembly_price, 2, '.', ' ')); ?> ₼</td>
                        <td class="px-4 py-4 text-right text-black/70"><?php echo e(number_format((float) $item->reserve_percent, 2, '.', ' ')); ?>%</td>
                        <td class="px-4 py-4 text-black/70"><?php echo e($item->comment ?: '—'); ?></td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr><td colspan="19" class="px-4 py-12 text-center text-black/55"><?php echo e(t('portal.no_procurement')); ?></td></tr>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($items->isNotEmpty()): ?>
        
        <div class="mt-4 flex flex-col items-end gap-2 rounded-ds-xl border border-black/8 bg-gray-soft2/60 px-5 py-4">
            <div class="flex w-full max-w-sm items-center justify-between gap-4">
                <span class="text-helper font-semibold text-black/60"><?php echo e(t('portal.procurement_total_before')); ?></span>
                <span class="text-body"><?php echo e(number_format($totalBeforeDiscount, 2, '.', ' ')); ?> ₼</span>
            </div>
            <div class="flex w-full max-w-sm items-center justify-between gap-4">
                <span class="text-helper font-semibold text-black/60"><?php echo e(t('portal.procurement_total_after')); ?></span>
                <span class="text-title font-semibold"><?php echo e(number_format($totalWithDiscount, 2, '.', ' ')); ?> ₼</span>
            </div>
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
<?php /**PATH C:\Users\User\Herd\ArchiCRM\resources\views/portal/procurement.blade.php ENDPATH**/ ?>