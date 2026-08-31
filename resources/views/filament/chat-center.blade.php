<x-filament-panels::page>
    @php
        $conversations = $this->getConversations();
        $active = $this->getActiveProject();
    @endphp

    <div class="grid gap-4 lg:grid-cols-[300px_1fr]">
        {{-- Conversation list --}}
        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="border-b border-gray-200 px-4 py-3 text-sm font-bold dark:border-white/10">Söhbətlər</div>
            <div class="max-h-[65vh] divide-y divide-gray-100 overflow-y-auto dark:divide-white/5">
                @forelse ($conversations as $c)
                    <a href="{{ \App\Filament\Pages\ChatCenter::getUrl(['project' => $c['project']->id]) }}"
                        @class([
                            'block px-4 py-3 transition-colors hover:bg-gray-50 dark:hover:bg-white/5',
                            'bg-gray-50 dark:bg-white/5' => $active?->id === $c['project']->id,
                        ])>
                        <div class="flex items-center justify-between gap-2">
                            <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $c['project']->name }}</p>
                            @if ($c['unread'] > 0)
                                <span class="shrink-0 rounded-full bg-red-600 px-2 py-0.5 text-[11px] font-bold text-white">{{ $c['unread'] }}</span>
                            @endif
                        </div>
                        <p class="truncate text-[12px] text-gray-500 dark:text-gray-400">
                            {{ $c['project']->client?->name }}
                        </p>
                        @if ($c['last'])
                            <p class="mt-1 truncate text-[12px] text-gray-400 dark:text-gray-500">
                                {{ \Illuminate\Support\Str::limit($c['last']->body, 48) }}
                            </p>
                        @endif
                    </a>
                @empty
                    <p class="px-4 py-8 text-center text-sm text-gray-400">Layihə yoxdur</p>
                @endforelse
            </div>
        </div>

        {{-- Thread --}}
        <div class="flex h-[70vh] flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            @if ($active)
                <div class="border-b border-gray-200 px-5 py-3 dark:border-white/10">
                    <p class="text-sm font-bold text-gray-950 dark:text-white">{{ $active->name }}</p>
                    <p class="text-[12px] text-gray-500">{{ $active->client?->name }}</p>
                </div>

                <div id="chatThread" class="flex-1 space-y-3 overflow-y-auto p-5"
                    data-poll-url="{{ route('staff.chat.poll', $active) }}"
                    data-send-url="{{ route('staff.chat.send', $active) }}">
                </div>

                <form id="chatForm" class="flex items-center gap-3 border-t border-gray-200 p-4 dark:border-white/10">
                    <input id="chatInput" autocomplete="off" maxlength="4000" required
                        placeholder="Mesajınızı yazın..."
                        class="h-11 flex-1 rounded-lg border border-gray-300 bg-white px-3.5 text-sm text-gray-950 outline-none focus:border-gray-950 dark:border-white/20 dark:bg-gray-800 dark:text-white">
                    <x-filament::button type="submit">Göndər</x-filament::button>
                </form>
            @else
                <div class="flex flex-1 items-center justify-center text-sm text-gray-400">
                    Söhbət seçin
                </div>
            @endif
        </div>
    </div>

    @if ($active)
    <script>
        (() => {
            const thread = document.getElementById('chatThread');
            const form = document.getElementById('chatForm');
            const input = document.getElementById('chatInput');
            const csrf = '{{ csrf_token() }}';
            let lastId = 0;
            let firstLoad = true;

            const beep = () => {
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.connect(gain); gain.connect(ctx.destination);
                    osc.frequency.value = 880;
                    gain.gain.setValueAtTime(0.06, ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.35);
                    osc.start(); osc.stop(ctx.currentTime + 0.4);
                } catch (e) {}
            };

            const bubble = (m) => {
                const wrap = document.createElement('div');
                wrap.className = 'flex ' + (m.mine ? 'justify-end' : 'justify-start');
                const box = document.createElement('div');
                box.className = 'max-w-[75%] rounded-lg px-4 py-2.5 text-sm ' +
                    (m.mine ? 'bg-gray-950 text-white dark:bg-white dark:text-gray-950' : 'bg-gray-100 text-gray-950 dark:bg-gray-800 dark:text-white');
                const meta = document.createElement('p');
                meta.className = 'mb-0.5 text-[11px] font-semibold opacity-60';
                meta.textContent = m.author + ' · ' + m.at;
                const body = document.createElement('p');
                body.textContent = m.body;
                box.append(meta, body);
                wrap.append(box);
                return wrap;
            };

            const poll = () => {
                fetch(thread.dataset.pollUrl + '?after=' + lastId, { headers: { Accept: 'application/json' } })
                    .then(r => r.json())
                    .then(d => {
                        if (!d.messages?.length) { firstLoad = false; return; }
                        let incoming = false;
                        d.messages.forEach(m => {
                            thread.append(bubble(m));
                            lastId = Math.max(lastId, m.id);
                            if (!m.mine) incoming = true;
                        });
                        thread.scrollTop = thread.scrollHeight;
                        if (incoming && !firstLoad) beep();
                        firstLoad = false;
                    })
                    .catch(() => {});
            };

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const body = input.value.trim();
                if (!body) return;
                input.value = '';
                fetch(thread.dataset.sendUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                    body: JSON.stringify({ body }),
                }).then(poll);
            });

            poll();
            setInterval(poll, 8000);
        })();
    </script>
    @endif
</x-filament-panels::page>
