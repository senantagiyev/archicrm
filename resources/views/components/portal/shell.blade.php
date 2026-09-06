@props(['title' => null, 'project' => null, 'active' => null])

@php
    $nav = $project ? [
        'overview'  => [route('portal.projects.show', $project), t('portal.nav_overview'),  'M3 11l9-8 9 8M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5'],
        'brief'     => [route('portal.brief', $project),         t('portal.nav_brief'),     'M8 4h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2M9.5 9h5M9.5 13h5M9.5 17h3'],
        'approvals' => [route('portal.approvals', $project),     t('portal.nav_approvals'), 'M22 11.1V12a10 10 0 1 1-5.9-9.1M22 4 12 14l-3-3'],
        'documents' => [route('portal.documents', $project),     t('portal.nav_documents'), 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z'],
        'payments'  => [route('portal.payments', $project),      t('portal.nav_payments'),  'M3 10h18M3 6h18a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1ZM7 15h2'],
        'chat'      => [route('portal.chat', $project),          t('portal.nav_chat'),      'M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5'],
    ] : [
        'projects'  => [route('portal.home'), t('portal.my_projects'), 'M4 20V8l8-5 8 5v12M9 20v-6h6v6'],
    ];
    $activeKey = $project ? $active : 'projects';
@endphp

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' — ' : '' }}ARCHI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-gray-soft2 text-ink">

    {{-- ── Desktop: dark left sidebar (mockup) ─────────────────────────── --}}
    <aside class="fixed inset-y-0 left-0 z-30 hidden w-[240px] flex-col bg-ink text-white lg:flex">
        <div class="px-6 pb-5 pt-6">
            <a href="{{ route('portal.home') }}"><x-archi-logo variant="light" /></a>
        </div>

        @if ($project)
            <div class="mx-6 mb-3 border-t border-white/10 pt-4">
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-white/35">Layihə</p>
                <p class="mt-1 truncate text-[13px] font-semibold text-white/85">{{ $project->name }}</p>
            </div>
        @endif

        <nav class="flex-1 space-y-1 px-3">
            @foreach ($nav as $key => [$url, $label, $icon])
                <a href="{{ $url }}" @if ($key === 'chat') data-nav-chat @endif
                   class="relative flex items-center gap-3 rounded-[10px] px-3 py-2.5 text-[14px] font-semibold transition-colors
                          {{ $activeKey === $key ? 'bg-yellow text-ink' : 'text-white/70 hover:bg-white/8 hover:text-white' }}">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                    <span class="truncate">{{ $label }}</span>
                    @if ($key === 'chat')
                        <span data-chat-dot hidden class="ml-auto inline-block h-2 w-2 rounded-pill bg-danger"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="border-t border-white/10 px-3 py-4">
            <form method="post" action="{{ route('locale.switch') }}" class="mb-3 flex items-center gap-1 px-3 text-[11px] font-bold uppercase">
                @csrf
                @foreach (['az', 'ru', 'en'] as $loc)
                    <button name="locale" value="{{ $loc }}"
                        class="rounded-ds px-2 py-1 {{ app()->getLocale() === $loc ? 'bg-white/15 text-white' : 'text-white/45 hover:text-white' }}">
                        {{ strtoupper($loc) }}
                    </button>
                @endforeach
            </form>
            @auth('customer')
                <form method="post" action="{{ route('portal.logout') }}">
                    @csrf
                    <button class="flex w-full items-center gap-3 rounded-[10px] px-3 py-2.5 text-[14px] font-semibold text-white/70 transition-colors hover:bg-white/8 hover:text-white">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                        {{ t('portal.logout') }}
                    </button>
                </form>
            @endauth
        </div>
    </aside>

    {{-- ── Mobile: dark top bar + scrollable nav ───────────────────────── --}}
    <header class="bg-ink text-white lg:hidden">
        <div class="flex h-[60px] items-center justify-between px-4">
            <a href="{{ route('portal.home') }}"><x-archi-logo variant="light" :sub="false" /></a>
            <div class="flex items-center gap-2">
                <form method="post" action="{{ route('locale.switch') }}" class="flex items-center gap-1 text-[11px] font-bold uppercase">
                    @csrf
                    @foreach (['az', 'ru', 'en'] as $loc)
                        <button name="locale" value="{{ $loc }}"
                            class="rounded-ds px-1.5 py-1 {{ app()->getLocale() === $loc ? 'bg-yellow text-ink' : 'text-white/50' }}">{{ strtoupper($loc) }}</button>
                    @endforeach
                </form>
                @auth('customer')
                    <form method="post" action="{{ route('portal.logout') }}">
                        @csrf
                        <button class="ui-btn ui-btn-on-ink h-8 px-3 text-[12px] font-semibold" data-hover="true">{{ t('portal.logout') }}</button>
                    </form>
                @endauth
            </div>
        </div>
        <nav class="flex items-center gap-1 overflow-x-auto border-t border-white/10 px-2 py-2">
            @foreach ($nav as $key => [$url, $label, $icon])
                <a href="{{ $url }}" @if ($key === 'chat') data-nav-chat @endif
                   class="relative whitespace-nowrap rounded-[8px] px-3 py-1.5 text-[13px] font-semibold
                          {{ $activeKey === $key ? 'bg-yellow text-ink' : 'text-white/65' }}">
                    {{ $label }}
                    @if ($key === 'chat')
                        <span data-chat-dot hidden class="absolute -right-0.5 -top-0.5 inline-block h-2 w-2 rounded-pill bg-danger"></span>
                    @endif
                </a>
            @endforeach
        </nav>
    </header>

    {{-- ── Content ─────────────────────────────────────────────────────── --}}
    <div class="lg:pl-[240px]">
        <div class="hidden h-[64px] items-center justify-between border-b border-black/8 bg-white px-8 lg:flex">
            <p class="text-[15px] font-bold">{{ $title ?? t('portal.my_projects') }}</p>
            @auth('customer')
                <div class="flex items-center gap-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-ink text-[12px] font-bold text-white">
                        {{ mb_strtoupper(mb_substr(auth('customer')->user()->name, 0, 1)) }}
                    </span>
                    <span class="text-[13px] font-semibold">{{ auth('customer')->user()->name }}</span>
                </div>
            @endauth
        </div>

        <main class="mx-auto max-w-[1180px] px-5 py-7 lg:px-8">
            @if (session('status'))
                <div class="mb-5 rounded-[12px] border border-ok/30 bg-ok-soft px-4 py-3 text-sm font-medium text-ok">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-5 rounded-[12px] border border-error/30 bg-error-soft px-4 py-3 text-sm font-medium text-error">
                    {{ $errors->first() }}
                </div>
            @endif

            {{ $slot }}
        </main>

        <footer class="mx-auto max-w-[1180px] px-5 pb-8 pt-2 text-[12px] text-black/40 lg:px-8">
            © {{ date('Y') }} ARCHI
        </footer>
    </div>

    @auth('customer')
    <script>
        // Global chat sound + unread dot for the customer, on every portal page.
        (() => {
            const url = @json(route('portal.chat.unread'));
            let last = null;

            const beep = () => {
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    const play = (freq, start) => {
                        const osc = ctx.createOscillator();
                        const gain = ctx.createGain();
                        osc.connect(gain); gain.connect(ctx.destination);
                        osc.frequency.value = freq;
                        gain.gain.setValueAtTime(0.06, ctx.currentTime + start);
                        gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + 0.3);
                        osc.start(ctx.currentTime + start); osc.stop(ctx.currentTime + start + 0.35);
                    };
                    play(880, 0); play(660, 0.18);
                } catch (e) {}
            };

            const check = () => {
                fetch(url, { headers: { Accept: 'application/json' } })
                    .then(r => r.json())
                    .then(d => {
                        document.querySelectorAll('[data-chat-dot]').forEach(el => el.hidden = !(d.count > 0));
                        if (last !== null && d.count > last) beep();
                        last = d.count;
                    })
                    .catch(() => {});
            };

            check();
            setInterval(check, 15000);
        })();
    </script>
    @endauth
</body>
</html>
