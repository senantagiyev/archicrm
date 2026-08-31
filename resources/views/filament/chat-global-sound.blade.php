@auth
<script>
    // Global chat sound: polls the unread total; a rising count means a new
    // incoming message somewhere → play a beep, wherever the user is in the panel.
    (() => {
        const url = @json(route('staff.chat.unread'));
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
