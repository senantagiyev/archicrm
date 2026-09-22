<x-portal.shell :title="t('portal.nav_chat')" :project="$project" active="chat">
    <h1 class="mb-6 text-heading font-semibold">{{ t('portal.nav_chat') }}</h1>

    {{-- Axtarış serverdə işləyir (GET ?q=) — JS olmadan da nəticə verir. --}}
    <form method="GET" action="{{ route('portal.chat', $project) }}" class="mb-4 flex items-center gap-3">
        <input type="search" name="q" value="{{ $query }}"
            placeholder="{{ t('portal.chat_search_placeholder') }}"
            class="h-11 flex-1 rounded-ds border border-black/20 px-3.5 text-body outline-none focus:border-ink">
        <button class="ui-btn h-11 px-5 text-[14px] font-semibold" data-hover="true">
            {{ t('portal.chat_search') }}
        </button>
        @if ($query !== '')
            <a href="{{ route('portal.chat', $project) }}" class="text-helper underline">
                {{ t('portal.chat_search_clear') }}
            </a>
        @endif
    </form>

    @if ($results !== null)
        <div class="mb-4 rounded-ds-xl border border-black/8 bg-card p-5">
            <p class="mb-3 text-helper font-semibold text-black/55">
                {{ t('portal.chat_search_results') }} — {{ count($results) }}
            </p>

            @forelse ($results as $m)
                <div class="mb-3 rounded-ds-lg bg-gray-soft px-4 py-3 last:mb-0">
                    <p class="mb-1 text-helper font-medium text-black/55">{{ $m['author'] }} · {{ $m['at'] }}</p>
                    @if (filled($m['body']))
                        <p class="text-body">{{ $m['body'] }}</p>
                    @endif
                    @if ($m['attachment'])
                        <a href="{{ $m['attachment']['url'] }}" class="text-body underline">
                            {{ $m['attachment']['name'] }} ({{ $m['attachment']['size'] }})
                        </a>
                    @endif
                </div>
            @empty
                <p class="text-body text-black/55">{{ t('portal.chat_search_empty') }}</p>
            @endforelse
        </div>
    @endif

    <div class="flex h-[60vh] flex-col overflow-hidden rounded-ds-xl border border-black/8 bg-card">
        <div id="chatThread" class="flex-1 space-y-3 overflow-y-auto p-5"
            data-poll-url="{{ route('portal.chat.poll', $project) }}"
            data-send-url="{{ route('portal.chat.send', $project) }}"
            data-voice-label="{{ t('portal.chat_voice_message') }}">
        </div>

        {{-- Seçilmiş faylın adı göndərilməmişdən əvvəl burada görünür. --}}
        <div id="chatFileBar" class="hidden items-center gap-2 border-t border-black/8 px-4 py-2 text-helper">
            <span id="chatFileName" class="truncate"></span>
            <button type="button" id="chatFileClear" class="underline">{{ t('portal.chat_file_clear') }}</button>
        </div>

        {{-- Emoji seçici: kitabxanasız, sadə düymə lövhəsi. --}}
        <div id="chatEmojiPanel" class="hidden flex-wrap gap-1 border-t border-black/8 px-4 py-2"></div>

        <form id="chatForm" class="flex items-center gap-2 border-t border-black/8 p-4">
            @csrf
            <input type="file" id="chatFile" class="hidden"
                accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.txt,.csv">

            <button type="button" id="chatAttach" title="{{ t('portal.chat_attach') }}"
                class="ui-btn h-11 w-11 shrink-0 text-[18px] font-semibold">+</button>

            <input id="chatInput" autocomplete="off" maxlength="4000"
                placeholder="{{ t('portal.chat_placeholder') }}"
                class="h-11 flex-1 rounded-ds border border-black/20 px-3.5 text-body outline-none focus:border-ink">

            <button type="button" id="chatEmoji" title="{{ t('portal.chat_emoji') }}"
                class="ui-btn h-11 w-11 shrink-0 text-[18px]">🙂</button>

            <button type="button" id="chatMic" title="{{ t('portal.chat_record') }}"
                class="ui-btn h-11 w-11 shrink-0 text-[18px]">🎤</button>

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
            const fileInput = document.getElementById('chatFile');
            const fileBar = document.getElementById('chatFileBar');
            const fileName = document.getElementById('chatFileName');
            const emojiPanel = document.getElementById('chatEmojiPanel');
            const micBtn = document.getElementById('chatMic');
            const csrf = form.querySelector('input[name="_token"]').value;
            const voiceLabel = thread.dataset.voiceLabel;
            const micError = @json(t('portal.chat_no_mic'));
            const recordLabel = @json(t('portal.chat_record'));
            const recordStopLabel = @json(t('portal.chat_record_stop'));
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
                    (m.mine ? 'bg-accent-dark text-white' : (m.staff ? 'bg-sel-bg' : 'bg-gray-soft'));
                const meta = document.createElement('p');
                meta.className = 'mb-1 text-helper font-medium ' + (m.mine ? 'text-yellow' : 'text-black/55');
                meta.textContent = m.author + ' · ' + m.at;
                box.append(meta);

                if (m.body) {
                    const body = document.createElement('p');
                    body.textContent = m.body;
                    box.append(body);
                }

                if (m.attachment && m.kind === 'voice') {
                    // Səs də avtorizasiyalı marşrutdan oxunur, birbaşa disk linki yoxdur.
                    const label = document.createElement('p');
                    label.className = 'text-helper';
                    label.textContent = voiceLabel;
                    const audio = document.createElement('audio');
                    audio.controls = true;
                    audio.src = m.attachment.url;
                    audio.className = 'mt-1 w-full';
                    box.append(label, audio);
                } else if (m.attachment) {
                    const link = document.createElement('a');
                    link.href = m.attachment.url;
                    link.className = 'mt-1 block underline';
                    link.textContent = m.attachment.name + ' (' + m.attachment.size + ')';
                    box.append(link);
                }

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

            // Hər üç mesaj tipi eyni endpoint-ə FormData ilə gedir.
            const post = (body, file, kind) => {
                const data = new FormData();
                if (body) data.append('body', body);
                if (file) data.append('attachment', file, file.name);
                data.append('kind', kind);

                return fetch(thread.dataset.sendUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                    body: data,
                }).then(poll);
            };

            // --- Fayl ---
            const clearFile = () => {
                fileInput.value = '';
                fileBar.classList.add('hidden');
                fileBar.classList.remove('flex');
            };

            document.getElementById('chatAttach').addEventListener('click', () => fileInput.click());
            document.getElementById('chatFileClear').addEventListener('click', clearFile);

            fileInput.addEventListener('change', () => {
                if (!fileInput.files.length) { clearFile(); return; }
                fileName.textContent = fileInput.files[0].name;
                fileBar.classList.remove('hidden');
                fileBar.classList.add('flex');
            });

            // --- Emoji ---
            ['🙂', '😀', '😍', '👍', '🙏', '👌', '🎉', '🔥', '❤️', '😅', '😢', '🤔'].forEach(e => {
                const b = document.createElement('button');
                b.type = 'button';
                b.textContent = e;
                b.className = 'rounded-ds px-2 py-1 text-[18px] hover:bg-gray-soft';
                b.addEventListener('click', () => { input.value += e; input.focus(); });
                emojiPanel.append(b);
            });

            document.getElementById('chatEmoji').addEventListener('click', () => {
                emojiPanel.classList.toggle('hidden');
                emojiPanel.classList.toggle('flex');
            });

            // --- Səsli mesaj (MediaRecorder) ---
            let recorder = null;
            let chunks = [];

            micBtn.addEventListener('click', async () => {
                if (recorder && recorder.state === 'recording') {
                    recorder.stop();
                    return;
                }

                if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
                    alert(micError);
                    return;
                }

                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                    // Brauzerlər fərqli konteyner dəstəkləyir — webm yoxdursa ogg.
                    const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/ogg';
                    const ext = mime === 'audio/webm' ? 'webm' : 'ogg';
                    recorder = new MediaRecorder(stream, { mimeType: mime });
                    chunks = [];

                    recorder.addEventListener('dataavailable', e => chunks.push(e.data));
                    recorder.addEventListener('stop', () => {
                        stream.getTracks().forEach(t => t.stop());
                        micBtn.classList.remove('ui-btn-primary');
                        micBtn.title = recordLabel;
                        const blob = new Blob(chunks, { type: mime });
                        if (blob.size > 0) post(null, new File([blob], 'voice.' + ext, { type: mime }), 'voice');
                    });

                    recorder.start();
                    micBtn.classList.add('ui-btn-primary');
                    micBtn.title = recordStopLabel;
                } catch (e) {
                    alert(micError);
                }
            });

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const body = input.value.trim();
                const file = fileInput.files[0] || null;
                // Mətnsiz fayl göndərmək olar, amma ikisi də boşdursa yox.
                if (!body && !file) return;
                input.value = '';
                clearFile();
                emojiPanel.classList.add('hidden');
                emojiPanel.classList.remove('flex');
                post(body, file, file ? 'file' : 'text');
            });

            poll();
            setInterval(poll, 8000);
        })();
    </script>
</x-portal.shell>
