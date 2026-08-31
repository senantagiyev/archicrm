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
                ] as $key => [$url, $label])
                    <a href="{{ $url }}"
                        class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-semibold transition-colors
                            {{ $active === $key ? 'border-yellow-line text-ink' : 'border-transparent text-black/50 hover:text-ink' }}">
                        {{ $label }}
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
</body>
</html>
