@props(['title' => null, 'project' => null, 'active' => null])

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ? $title.' — ' : '' }}Archi CRM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite('resources/css/app.css')
</head>
<body class="min-h-screen bg-gray-soft2">
    <header class="bg-ink text-white">
        <div class="mx-auto flex h-16 max-w-[1240px] items-center justify-between px-5">
            <a href="{{ route('portal.home') }}" class="flex items-center gap-2">
                <span class="inline-block h-3 w-3 bg-yellow"></span>
                <span class="text-lg font-bold tracking-wide">ARCHI CRM</span>
            </a>

            <div class="flex items-center gap-4">
                <form method="post" action="{{ route('locale.switch') }}" class="flex items-center gap-1 text-[12px] font-semibold uppercase">
                    @csrf
                    @foreach (['az', 'ru', 'en'] as $loc)
                        <button name="locale" value="{{ $loc }}"
                            class="rounded-ds px-2 py-1 {{ app()->getLocale() === $loc ? 'bg-yellow text-ink' : 'text-white/60 hover:text-white' }}">
                            {{ strtoupper($loc) }}
                        </button>
                    @endforeach
                </form>

                @auth('customer')
                    <span class="hidden text-sm text-white/70 sm:block">{{ auth('customer')->user()->name }}</span>
                    <form method="post" action="{{ route('portal.logout') }}">
                        @csrf
                        <button class="ui-btn ui-btn-on-ink h-9 px-4 text-[13px] font-semibold" data-hover="true">
                            {{ t('portal.logout') }}
                        </button>
                    </form>
                @endauth
            </div>
        </div>
    </header>

    @if ($project)
        <nav class="border-b border-black/10 bg-white">
            <div class="mx-auto flex max-w-[1240px] items-center gap-1 overflow-x-auto px-5">
                <span class="mr-3 hidden max-w-[220px] truncate py-3 text-sm font-bold sm:block">{{ $project->name }}</span>
                @foreach ([
                    'overview' => [route('portal.projects.show', $project), t('portal.nav_overview')],
                    'brief' => [route('portal.brief', $project), t('portal.nav_brief')],
                    'approvals' => [route('portal.approvals', $project), t('portal.nav_approvals')],
                    'documents' => [route('portal.documents', $project), t('portal.nav_documents')],
                    'payments' => [route('portal.payments', $project), t('portal.nav_payments')],
                    'chat' => [route('portal.chat', $project), t('portal.nav_chat')],
                ] as $key => [$url, $label])
                    <a href="{{ $url }}" @if ($key === 'chat') data-nav-chat @endif
                        class="relative whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold transition-colors
                            {{ $active === $key ? 'border-yellow-line text-ink' : 'border-transparent text-black/50 hover:text-ink' }}">
                        {{ $label }}
                        @if ($key === 'chat')
                            <span data-chat-dot hidden class="absolute -right-0.5 top-2 inline-block h-2 w-2 rounded-pill bg-danger"></span>
                        @endif
                    </a>
                @endforeach
            </div>
        </nav>
    @endif

    <main class="mx-auto max-w-[1240px] px-5 py-8">
        @if (session('status'))
            <div class="mb-5 rounded-ds-md border border-ok/30 bg-ok-soft px-4 py-3 text-sm font-medium text-ok">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-5 rounded-ds-md border border-error/30 bg-error-soft px-4 py-3 text-sm font-medium text-error">
                {{ $errors->first() }}
            </div>
        @endif

        {{ $slot }}
    </main>

    <footer class="mx-auto max-w-[1240px] px-5 pb-8 pt-4 text-[12px] text-black/40">
        © {{ date('Y') }} Archi CRM
    </footer>

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
