<x-filament-panels::page>
    <div class="flex h-[65vh] flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div id="chatThread" class="flex-1 space-y-3 overflow-y-auto p-5"
            data-poll-url="{{ route('staff.chat.poll', $this->record) }}"
            data-send-url="{{ route('staff.chat.send', $this->record) }}">
        </div>

        <form id="chatForm" class="flex items-center gap-3 border-t border-gray-200 p-4 dark:border-white/10">
            <input id="chatInput" autocomplete="off" maxlength="4000" required
                placeholder="Mesajınızı yazın..."
                class="h-11 flex-1 rounded-lg border border-gray-300 bg-white px-3.5 text-sm text-gray-950 outline-none focus:border-gray-950 dark:border-white/20 dark:bg-gray-800 dark:text-white">
            <x-filament::button type="submit">Göndər</x-filament::button>
        </form>
    </div>

    <script>
        (() => {
            const thread = document.getElementById('chatThread');
            const form = document.getElementById('chatForm');
            const input = document.getElementById('chatInput');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}';
            let lastId = 0;

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
                        if (!d.messages?.length) return;
                        d.messages.forEach(m => { thread.append(bubble(m)); lastId = Math.max(lastId, m.id); });
                        thread.scrollTop = thread.scrollHeight;
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
</x-filament-panels::page>
