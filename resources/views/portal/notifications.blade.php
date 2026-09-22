<x-portal.shell :title="t('portal.nav_notifications')" active="notifications">
    @php
        // Roomix: All · Projects · Tasks · News. Dördü də öz açar ailəsindədir —
        // `files_all` kimi yad açarı burada təkrar işlətmək sonradan fayl
        // səhifəsinin etiketini dəyişəndə bildirişləri də səssizcə dəyişərdi.
        $filters = [
            'all' => t('portal.notifications_filter_all'),
            'projects' => t('portal.notifications_filter_projects'),
            'tasks' => t('portal.notifications_filter_tasks'),
            'news' => t('portal.notifications_filter_news'),
        ];
    @endphp

    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="mb-1 font-b2b text-[26px] font-extrabold tracking-tight">{{ t('portal.nav_notifications') }}</h1>
            <p class="text-[14px] text-black/50">{{ t('portal.notifications_hint') }}</p>
        </div>

        {{-- Oxunmamış qalmayıbsa düymə mənasızdır — göstərilmir. --}}
        @if ($unreadCount > 0)
            <form method="post" action="{{ route('portal.notifications.read-all') }}">
                @csrf
                <button class="ui-btn ui-btn-primary h-10 px-5 text-[13px] font-bold" data-hover="true">
                    {{ t('portal.notifications_mark_all') }}
                </button>
            </form>
        @endif
    </div>

    <nav class="mb-5 flex flex-wrap items-center gap-2" aria-label="{{ t('portal.nav_notifications') }}">
        @foreach ($filters as $key => $label)
            <a href="{{ route('portal.notifications', $key === 'all' ? [] : ['filter' => $key]) }}"
               @if ($filter === $key) aria-current="page" @endif
               class="rounded-pill px-4 py-2 text-[13px] font-semibold transition-colors
                      {{ $filter === $key ? 'bg-ink text-white' : 'bg-neutral-soft text-black/55 hover:text-ink' }}">
                {{ $label }}
            </a>
        @endforeach
    </nav>

    <div class="space-y-3">
        @forelse ($notifications as $notification)
            @php
                $data = $notification->data ?? [];
                $unread = $notification->read_at === null;
            @endphp

            {{-- Oxunmamış sətir: ağ fon + sarı sol zolaq; oxunmuş sətir sönükdür. --}}
            <div class="rounded-[16px] border p-5 {{ $unread ? 'border-l-4 border-l-yellow border-black/8 bg-white' : 'border-black/8 bg-white/60' }}">
                {{-- Keçid yalnız bildirişin özü verdikdə olur (AutomationAlert
                     `url`). Ayrıca düymə əvəzinə başlıq link edilir — belədə
                     yeni tərcümə açarı (düymə etiketi) lazım gəlmir. --}}
                @if (! empty($data['url']))
                    <a href="{{ $data['url'] }}"
                       class="text-[15px] underline-offset-2 hover:underline {{ $unread ? 'font-bold text-ink' : 'font-semibold text-black/60' }}">
                        {{ $data['title'] ?? '—' }}
                    </a>
                @else
                    <p class="text-[15px] {{ $unread ? 'font-bold text-ink' : 'font-semibold text-black/60' }}">
                        {{ $data['title'] ?? '—' }}
                    </p>
                @endif

                @if (! empty($data['body']))
                    <p class="mt-1 text-[14px] text-black/60">{{ $data['body'] }}</p>
                @endif

                <p class="mt-2 text-[13px] text-black/40">{{ $notification->created_at->format('d.m.Y H:i') }}</p>
            </div>
        @empty
            <div class="rounded-[16px] border border-black/8 bg-white px-5 py-12 text-center">
                <p class="text-[14px] font-semibold text-black/50">{{ t('portal.no_notifications') }}</p>
                <p class="mt-1 text-[13px] text-black/40">{{ t('portal.notifications_hint') }}</p>
            </div>
        @endforelse
    </div>

    @if ($notifications->hasPages())
        <div class="mt-6">{{ $notifications->links() }}</div>
    @endif
</x-portal.shell>
