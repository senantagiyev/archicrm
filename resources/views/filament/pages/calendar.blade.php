<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-gray-600 dark:text-gray-400">
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#2563eb"></span> Görüşlər</span>
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#f59e0b"></span> Tapşırıqlar</span>
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#dc2626"></span> Gecikmiş tapşırıq</span>
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#0d9488"></span> Mərhələ son tarixi</span>
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#7c3aed"></span> Ödənişlər</span>
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-sm" style="background:#be123c"></span> Hesab-fakturalar</span>
        </div>

        <div wire:ignore>
            <div id="archi-calendar"></div>
        </div>
    </div>

    @assets
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/locales-all.global.min.js"></script>
    @endassets

    @script
        <script>
            const el = document.getElementById('archi-calendar');

            if (el && ! el.__archiInit) {
                el.__archiInit = true;

                const calendar = new FullCalendar.Calendar(el, {
                    initialView: 'dayGridMonth',
                    locale: 'az',
                    firstDay: 1,
                    height: 'auto',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,listMonth',
                    },
                    buttonText: { today: 'Bu gün', month: 'Ay', week: 'Həftə', list: 'Siyahı' },
                    events: @js(route('calendar.events')),
                    eventClick: function (info) {
                        if (info.event.url) {
                            info.jsEvent.preventDefault();
                            window.location.href = info.event.url;
                        }
                    },
                });

                calendar.render();
            }
        </script>
    @endscript
</x-filament-panels::page>
