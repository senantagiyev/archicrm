@php
    $badge = [
        'critical' => ['🔴', 'text-danger-600 dark:text-danger-400'],
        'important' => ['🟠', 'text-warning-600 dark:text-warning-400'],
        'missing' => ['⚫', 'text-gray-500'],
    ];
@endphp

<div class="space-y-6 text-sm">
    <div>
        <h3 class="mb-2 font-bold">Risklər (Part 14)</h3>
        @forelse ($risks as $risk)
            @php [$icon, $class] = $badge[$risk['level']] ?? ['⚪', '']; @endphp
            <p class="mb-1.5 {{ $class }}">
                {{ $icon }} <span class="font-semibold">{{ $risk['code'] }}</span> — {{ $risk['message'] }}
            </p>
        @empty
            <p class="text-gray-500">Risk aşkarlanmadı.</p>
        @endforelse
    </div>

    <div>
        <h3 class="mb-2 font-bold">Doldurulmamış məcburi sahələr ({{ $missing->count() }})</h3>
        @forelse ($missing as $item)
            <p class="mb-1">
                <span class="font-semibold">{{ $item['room']?->label ?? $item['section']->getTranslation('name', 'az') }}</span>
                — {{ $item['question']->getTranslation('label', 'az') }}
            </p>
        @empty
            <p class="text-gray-500">Bütün məcburi sahələr doldurulub.</p>
        @endforelse
    </div>
</div>
