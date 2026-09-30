<x-portal.shell :title="t('portal.nav_brief')" :project="$project" active="brief">
    {{-- Studiya brifi hələ göndərməyib. Bura layihə kartından və ya köhnə
         linkdən gəlmək mümkündür, ona görə 404 yox, izahlı boş vəziyyət. --}}
    <div class="mx-auto max-w-xl rounded-ds-xl border border-black/8 bg-card px-8 py-14 text-center">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-neutral-soft text-black/40">
            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M8 4h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2M9.5 9h5M9.5 13h5M9.5 17h3"/>
            </svg>
        </span>
        <h1 class="mt-5 text-heading font-semibold">{{ t('portal.brief_not_presented_title') }}</h1>
        <p class="mt-2 text-body leading-relaxed text-black/60">{{ t('portal.brief_not_presented_body') }}</p>
        <a href="{{ route('portal.projects.show', $project) }}" class="ui-btn ui-btn-dark mt-8 h-11 px-6 text-sm font-bold" data-hover="true">
            {{ t('portal.nav_overview') }} →
        </a>
    </div>
</x-portal.shell>
