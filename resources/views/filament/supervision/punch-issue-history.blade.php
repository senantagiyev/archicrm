{{-- İradın «Müzakirə və tarixçə» paneli. Fotolar `public` diskindən açılır və
     endirilir — eyni fayl başqa yerə yenidən yüklənmədən istifadə oluna bilər.
     Bu panel yalnız heyət üçündür; portalda iradlar yoxdur. --}}
@php
    $disk = \Illuminate\Support\Facades\Storage::disk('public');
    $photoGroups = [
        'Fotolar' => $issue->photoPaths(),
        'Düzəlişdən sonra' => $issue->resolvedPhotoPaths(),
    ];
@endphp

<div class="space-y-6 text-sm">
    <dl class="grid grid-cols-2 gap-3">
        <div>
            <dt class="text-gray-500">Status</dt>
            <dd class="font-semibold">{{ $issue->status->label() }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Məsul</dt>
            <dd class="font-semibold">{{ $issue->responsible?->name ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Müddət</dt>
            <dd class="font-semibold">{{ $issue->due_date?->format('d.m.Y') ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Qeydə aldı</dt>
            <dd class="font-semibold">{{ $issue->createdBy?->name ?? '—' }} · {{ $issue->created_at?->format('d.m.Y H:i') }}</dd>
        </div>
        @if ($issue->room)
            <div>
                <dt class="text-gray-500">Otaq / zona</dt>
                <dd class="font-semibold">{{ $issue->room }}</dd>
            </div>
        @endif
        @if ($issue->diaryEntry)
            <div>
                <dt class="text-gray-500">Səfər / hesabat</dt>
                <dd class="font-semibold">{{ $issue->diaryEntry->kind->label() }} · {{ ($issue->diaryEntry->visited_on ?? $issue->diaryEntry->created_at)?->format('d.m.Y') }}</dd>
            </div>
        @endif
    </dl>

    @if (filled($issue->description))
        <p class="whitespace-pre-line">{{ $issue->description }}</p>
    @endif

    @if (filled($issue->resolution_note))
        <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <p class="font-semibold">Düzəlişin nəticəsi</p>
            <p class="whitespace-pre-line">{{ $issue->resolution_note }}</p>
            <p class="mt-1 text-gray-500">{{ $issue->resolvedBy?->name ?? '—' }} · {{ $issue->resolved_at?->format('d.m.Y H:i') }}</p>
        </div>
    @endif

    @foreach ($photoGroups as $groupLabel => $paths)
        @if ($paths !== [])
            <div>
                <p class="mb-2 font-semibold">{{ $groupLabel }} ({{ count($paths) }})</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($paths as $path)
                        @php $url = $disk->url($path); @endphp
                        <div style="width: 96px">
                            <a href="{{ $url }}" target="_blank" rel="noopener">
                                <img src="{{ $url }}" alt="{{ $groupLabel }} {{ $loop->iteration }}" loading="lazy"
                                     style="width: 96px; height: 96px; object-fit: cover; border-radius: 8px">
                            </a>
                            <a href="{{ $url }}" download class="text-primary-600 underline">Endir</a>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach

    <div>
        <p class="mb-2 font-semibold">Müzakirə və dəyişiklik tarixçəsi</p>
        <ol class="space-y-3">
            @forelse ($timeline as $entry)
                <li class="rounded-lg border border-gray-200 p-3 dark:border-white/10" data-timeline-kind="{{ $entry['kind'] }}">
                    <p>
                        <span class="font-semibold">{{ $entry['title'] }}</span>
                        <span class="text-gray-500">· {{ $entry['actor'] ?? 'Sistem' }} · {{ $entry['at']?->format('d.m.Y H:i') }}</span>
                    </p>
                    @foreach ($entry['lines'] as $line)
                        <p class="whitespace-pre-line">{{ $line }}</p>
                    @endforeach
                </li>
            @empty
                <li class="text-gray-500">Hələ heç bir qeyd yoxdur.</li>
            @endforelse
        </ol>
    </div>
</div>
