<x-filament-panels::page>
    @php
        $groups = $this->getGroupedTasks();
        $total = $this->getTotalCount();
    @endphp

    {{-- Filtr: yalnız mənim / bütün studiya --}}
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Göstər:</span>

        @foreach (['all' => 'Bütün studiya', 'mine' => 'Yalnız mənim'] as $value => $label)
            <button
                type="button"
                wire:click="$set('scope', '{{ $value }}')"
                @class([
                    'rounded-lg px-3 py-1.5 text-sm font-semibold ring-1 transition',
                    'bg-primary-600 text-white ring-primary-600' => $scope === $value,
                    'bg-white text-gray-700 ring-gray-950/10 hover:bg-gray-50 dark:bg-white/5 dark:text-gray-300 dark:ring-white/10' => $scope !== $value,
                ])
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($total === 0)
        <div class="rounded-2xl bg-white p-10 text-center shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">Hələ tapşırıq yoxdur</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Studiyanın işini planlamaq üçün ilk tapşırığı yaradın.
            </p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($this->statuses() as $status)
                @php $tasks = $groups[$status->value]; @endphp

                <div class="flex flex-col gap-3 rounded-2xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <div class="flex items-center justify-between px-1">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">{{ $status->label() }}</h3>
                        <span class="rounded-full bg-white px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-gray-950/10 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10">
                            {{ $tasks->count() }}
                        </span>
                    </div>

                    @forelse ($tasks as $task)
                        @php $overdue = $task->isOverdue(); @endphp

                        <div @class([
                            'rounded-xl bg-white p-3 shadow-sm ring-1 dark:bg-gray-900',
                            'ring-danger-500/50 dark:ring-danger-400/40' => $overdue,
                            'ring-gray-950/5 dark:ring-white/10' => ! $overdue,
                        ])>
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ $task->title }}</p>

                            @if ($task->project)
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $task->project->name }}</p>
                            @endif

                            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <span class="text-gray-600 dark:text-gray-300">
                                    {{ $task->assignee?->name ?? 'İcraçı təyin edilməyib' }}
                                </span>

                                @if ($task->deadline)
                                    <span @class([
                                        'rounded-full px-2 py-0.5 font-medium',
                                        'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' => $overdue,
                                        'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $overdue,
                                    ])>
                                        {{ $task->deadline->format('d.m.Y') }}@if ($overdue) · gecikib @endif
                                    </span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="px-1 pb-1 text-xs text-gray-400 dark:text-gray-500">Boşdur</p>
                    @endforelse
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
