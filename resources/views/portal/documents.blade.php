<x-portal.shell :title="t('portal.nav_documents')" :project="$project" active="documents">
    <h1 class="mb-6 text-2xl font-bold">{{ t('portal.nav_documents') }}</h1>

    <div class="overflow-hidden rounded-ds-md border border-black/10 bg-white">
        @forelse ($documents as $document)
            <div class="flex items-center gap-4 border-b border-black/5 px-5 py-4 last:border-0">
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold">{{ $document->title }}</p>
                    <p class="text-[12px] text-black/40">{{ $document->type->label() }} · {{ $document->created_at->format('d.m.Y') }}</p>
                </div>
                <a href="{{ route('portal.documents.download', [$project, $document]) }}"
                    class="ui-btn ui-btn-outline h-9 px-4 text-[13px] font-semibold" data-hover="true">
                    {{ t('portal.download') }}
                </a>
            </div>
        @empty
            <p class="px-5 py-10 text-center text-sm text-black/40">{{ t('portal.no_documents') }}</p>
        @endforelse
    </div>
</x-portal.shell>
