<x-portal.shell :title="t('portal.nav_chat')" :project="$project" active="chat">
    <h1 class="mb-6 text-heading font-semibold">{{ t('portal.nav_chat') }}</h1>

    <div class="flex h-[60vh] flex-col overflow-hidden rounded-ds-xl border border-black/8 bg-white">
        <div id="chatThread" class="flex-1 space-y-3 overflow-y-auto p-5"
            data-poll-url="{{ route('portal.chat.poll', $project) }}"
            data-send-url="{{ route('portal.chat.send', $project) }}">
        </div>

        <form id="chatForm" class="flex items-center gap-3 border-t border-black/8 p-4">
            @csrf
            <input id="chatInput" autocomplete="off" maxlength="4000" required
                placeholder="{{ t('portal.chat_placeholder') }}"
                class="h-11 flex-1 rounded-ds border border-black/20 px-3.5 text-body outline-none focus:border-ink">
            <button class="ui-btn ui-btn-primary h-11 px-6 text-[14px] font-semibold" data-hover="true">
                {{ t('portal.chat_send') }}
            </button>
        </form>
    </div>

    <script>
        (() => {
            const thread = document.getElementById('chatThread');
            const form = document.getElementById('chatForm');
            const input = document.getElementById('chatInput');
            const csrf = form.querySelector('input[name="_token"]').value;
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
                box.className = 'max-w-[75%] rounded-ds-lg px-4 py-3 text-body ' +
                    (m.mine ? 'bg-ink text-white' : (m.staff ? 'bg-sel-bg' : 'bg-gray-soft'));
                const meta = document.createElement('p');
                meta.className = 'mb-1 text-helper font-medium ' + (m.mine ? 'text-yellow' : 'text-black/55');
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
</x-portal.shell>
