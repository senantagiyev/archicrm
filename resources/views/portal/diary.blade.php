<x-portal.shell :title="t('portal.nav_diary')" :project="$project" active="diary">
    <div class="mb-5">
        <h1 class="text-heading font-semibold">{{ t('portal.nav_diary') }}</h1>
        <p class="mt-1 text-helper text-black/55">{{ t('portal.diary_intro') }}</p>
    </div>

    @forelse ($entries as $entry)
        @php
            // Fotolar Filament-in `public` diskinə yüklənir; portalda ayrıca
            // yükləmə marşrutu yoxdur, ona görə birbaşa disk URL-i ilə göstərilir.
            $photos = collect($entry->photos ?? [])->filter()->values();
        @endphp

        <article class="mb-4 rounded-ds-xl border border-black/8 bg-white px-5 py-4 last:mb-0">
            <div class="mb-2 flex flex-wrap items-center gap-2 text-helper text-black/55">
                <span class="font-semibold text-black/70">{{ $entry->published_at->format('d.m.Y H:i') }}</span>
                @if ($entry->author)
                    <span aria-hidden="true">·</span>
                    <span>{{ $entry->author->name }}</span>
                @endif
            </div>

            <p class="whitespace-pre-line text-body">{{ $entry->body }}</p>

            @if ($photos->isNotEmpty())
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    {{-- Foto birbaşa disk linki ilə deyil, avtorizasiyalı marşrutla
                         verilir: public disk linki sessiya tələb etmir və onu bilən
                         kənar şəxs obyektin fotosunu aça bilərdi. --}}
                    @foreach ($photos as $i => $photo)
                        @php $src = route('portal.diary.photo', [$project->id, $entry->id, $i]); @endphp
                        <a href="{{ $src }}" target="_blank" rel="noopener"
                           class="block overflow-hidden rounded-ds-lg border border-black/8 bg-neutral-soft">
                            <img src="{{ $src }}" alt="" loading="lazy" class="h-32 w-full object-cover">
                        </a>
                    @endforeach
                </div>
            @endif
        </article>
    @empty
        <div class="rounded-ds-xl border border-black/8 bg-white px-5 py-12 text-center">
            <p class="text-body text-black/55">{{ t('portal.no_diary') }}</p>
        </div>
    @endforelse
</x-portal.shell>
