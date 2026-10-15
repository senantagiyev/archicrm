{{--
    Material kartı (Fayllar → «Kartı aç»): versiyalar, fayllar, şərhlər və
    razılaşdırma tarixçəsi. Fayl linkləri avtorizasiyalı `materials.download`
    marşrutuna gedir. Daxili şərhlər açıq işarələnir — müştəriyə çıxmır.

    @var \App\Models\Deliverable $material
    @var \Illuminate\Support\Collection<int, \App\Models\Approval> $history
    @var \App\Enums\MaterialApprovalState $state
--}}
@php
    $badge = fn (string $color) => match ($color) {
        'success' => 'bg-green-100 text-green-800',
        'warning' => 'bg-amber-100 text-amber-800',
        'danger' => 'bg-red-100 text-red-800',
        'info' => 'bg-sky-100 text-sky-800',
        default => 'bg-gray-100 text-gray-700',
    };
@endphp

<div class="space-y-6 text-sm">
    <dl class="grid grid-cols-2 gap-3">
        <div>
            <dt class="text-gray-500">Kateqoriya</dt>
            <dd class="font-medium">{{ $material->type?->label() }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Mərhələ</dt>
            <dd class="font-medium">{{ $material->stage?->name ?? 'Ümumi (mərhələsiz)' }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">Müştəri görür</dt>
            <dd class="font-medium">
                {{ $material->publishedVersion ? 'v'.$material->publishedVersion->version_number : 'Heç nə (daxili)' }}
            </dd>
        </div>
        <div>
            <dt class="text-gray-500">Razılaşdırma (cari versiya)</dt>
            <dd><span class="rounded px-2 py-0.5 text-xs font-semibold {{ $badge($state->color()) }}">{{ $state->label() }}</span></dd>
        </div>
        @if ($material->description)
            <div class="col-span-2">
                <dt class="text-gray-500">Təsvir</dt>
                <dd class="whitespace-pre-line">{{ $material->description }}</dd>
            </div>
        @endif
    </dl>

    <section>
        <h3 class="mb-2 text-base font-bold">Versiyalar</h3>
        <ol class="space-y-3">
            @forelse ($material->versions as $version)
                <li class="rounded-lg border border-gray-200 p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-bold">v{{ $version->version_number }}</span>
                        <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $badge($version->status->color()) }}">{{ $version->status->label() }}</span>
                        @if ((int) $material->current_version_id === (int) $version->id)
                            <span class="rounded bg-gray-900 px-2 py-0.5 text-xs font-semibold text-white">Cari</span>
                        @endif
                        @if ((int) $material->published_version_id === (int) $version->id)
                            <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $badge('info') }}">Müştəriyə dərc edilib</span>
                        @endif
                        @if ($version->isClientUploaded())
                            <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $badge('warning') }}">Müştəri yükləyib</span>
                        @endif
                        <span class="ms-auto text-xs text-gray-500">
                            {{ $version->created_at?->format('d.m.Y H:i') }}
                            · {{ $version->uploadedBy?->name ?? $version->createdBy?->name ?? '—' }}
                        </span>
                    </div>

                    @if ($version->change_summary)
                        <p class="mt-1 text-gray-600">{{ $version->change_summary }}</p>
                    @endif

                    <ul class="mt-2 space-y-1">
                        @foreach ($version->files() as $index => $file)
                            <li>
                                <a href="{{ route('materials.download', ['version' => $version, 'index' => $index]) }}"
                                   target="_blank" class="text-primary-600 underline">{{ $file['name'] }}</a>
                                @if ($file['size'])
                                    <span class="text-xs text-gray-500">· {{ number_format($file['size'] / 1024, 0, '.', ' ') }} KB</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if ($version->comments->isNotEmpty())
                        <div class="mt-3 space-y-2 border-t border-gray-100 pt-2">
                            @foreach ($version->comments as $comment)
                                <div>
                                    <span class="font-semibold">{{ $comment->author?->name ?? '—' }}</span>
                                    <span class="rounded px-1.5 py-0.5 text-xs font-semibold {{ $comment->is_internal ? $badge('gray') : $badge('info') }}">
                                        {{ $comment->is_internal ? 'Daxili' : 'Müştəriyə açıq' }}
                                    </span>
                                    <span class="text-xs text-gray-500">{{ $comment->created_at?->format('d.m.Y H:i') }}</span>
                                    <p class="whitespace-pre-line">{{ $comment->body }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </li>
            @empty
                <li class="text-gray-500">Versiya yoxdur.</li>
            @endforelse
        </ol>
    </section>

    <section>
        <h3 class="mb-2 text-base font-bold">Razılaşdırma tarixçəsi</h3>
        @if ($history->isEmpty())
            <p class="text-gray-500">Hələ razılaşdırmaya göndərilməyib.</p>
        @else
            <table class="w-full text-left text-xs">
                <thead class="text-gray-500">
                    <tr>
                        <th class="py-1 pe-2">Versiya</th>
                        <th class="py-1 pe-2">Göndərən / tarix</th>
                        <th class="py-1 pe-2">Nəticə</th>
                        <th class="py-1">Kim / nə vaxt</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($history as $approval)
                        @php
                            $result = $approval->status === \App\Enums\ApprovalStatus::Draft
                                ? ['Geri çəkilib / əvəzlənib', 'gray']
                                : [\App\Enums\MaterialApprovalState::fromApprovalStatus($approval->status)->label(), \App\Enums\MaterialApprovalState::fromApprovalStatus($approval->status)->color()];
                        @endphp
                        <tr class="border-t border-gray-100 align-top">
                            <td class="py-1 pe-2">
                                @if ($approval->approvable_type === 'deliverable')
                                    {{ $approval->deliverableVersion ? 'v'.$approval->deliverableVersion->version_number : '—' }}
                                @else
                                    köçürülmədən əvvəl
                                @endif
                            </td>
                            <td class="py-1 pe-2">{{ $approval->requestedBy?->name ?? '—' }}<br>{{ $approval->created_at?->format('d.m.Y H:i') }}</td>
                            <td class="py-1 pe-2">
                                <span class="rounded px-1.5 py-0.5 font-semibold {{ $badge($result[1]) }}">{{ $result[0] }}</span>
                                @if ($approval->comment)
                                    <p class="mt-1 whitespace-pre-line">{{ $approval->comment }}</p>
                                @endif
                            </td>
                            <td class="py-1">
                                {{ $approval->clientUser?->name ?? '—' }}
                                @if ($approval->decided_at)<br>{{ $approval->decided_at->format('d.m.Y H:i') }}@endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
</div>
