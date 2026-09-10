<x-filament-panels::page>
    <div class="calendar-card overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="border-b border-gray-200/80 px-4 py-4 sm:px-6 dark:border-white/10">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-gray-950 dark:text-white">İş planı</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Görüşləri, son tarixləri və ödənişləri bir yerdə izləyin.</p>
                </div>
                <span class="hidden rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-600 sm:inline-flex dark:bg-white/10 dark:text-gray-300">
                    Hadisəyə klikləyərək detallara keçin
                </span>
            </div>

            <div class="calendar-legend mt-4 flex gap-2 overflow-x-auto pb-1" aria-label="Təqvim göstəriciləri">
                <span class="calendar-legend-item"><i style="--legend-color:#2563eb"></i>Görüşlər</span>
                <span class="calendar-legend-item"><i style="--legend-color:#f59e0b"></i>Tapşırıqlar</span>
                <span class="calendar-legend-item"><i style="--legend-color:#dc2626"></i>Gecikmiş</span>
                <span class="calendar-legend-item"><i style="--legend-color:#0d9488"></i>Mərhələ sonu</span>
                <span class="calendar-legend-item"><i style="--legend-color:#7c3aed"></i>Ödənişlər</span>
                <span class="calendar-legend-item"><i style="--legend-color:#be123c"></i>Hesab-fakturalar</span>
            </div>
        </div>

        <div class="calendar-stage min-w-0 p-3 sm:p-6" wire:ignore>
            <div id="archi-calendar"></div>
        </div>
    </div>

    @once
        <style>
            .calendar-legend { scrollbar-width: thin; }
            .calendar-legend-item { display:inline-flex; flex:none; align-items:center; gap:.45rem; border:1px solid rgb(229 231 235); border-radius:999px; padding:.35rem .65rem; color:rgb(75 85 99); font-size:.75rem; font-weight:500; line-height:1rem; white-space:nowrap; }
            .dark .calendar-legend-item { border-color:rgb(255 255 255 / .1); color:rgb(209 213 219); }
            .calendar-legend-item i { width:.5rem; height:.5rem; flex:none; border-radius:999px; background:var(--legend-color); box-shadow:0 0 0 3px color-mix(in srgb, var(--legend-color) 14%, transparent); }
            .calendar-card .fc { --fc-border-color:rgb(229 231 235); --fc-neutral-bg-color:rgb(249 250 251); --fc-page-bg-color:transparent; --fc-today-bg-color:rgb(253 254 0 / .11); color:rgb(17 24 39); font-size:.875rem; }
            .dark .calendar-card .fc { --fc-border-color:rgb(255 255 255 / .1); --fc-neutral-bg-color:rgb(255 255 255 / .04); --fc-today-bg-color:rgb(253 254 0 / .07); color:rgb(229 231 235); }
            .calendar-card .fc .fc-toolbar { gap:1rem; margin-bottom:1.25rem; }
            .calendar-card .fc .fc-toolbar-title { font-size:1.25rem; font-weight:700; letter-spacing:-.025em; }
            .calendar-card .fc .fc-button { border:0; border-radius:.5rem; background:#111827; box-shadow:none; font-size:.8125rem; font-weight:600; line-height:1.25rem; padding:.5rem .75rem; transition:background-color .15s, transform .15s; }
            .calendar-card .fc .fc-button:hover { background:#374151; }
            .calendar-card .fc .fc-button:active { transform:translateY(1px); }
            .calendar-card .fc .fc-button:focus { box-shadow:0 0 0 3px rgb(17 24 39 / .16); }
            .calendar-card .fc .fc-button-primary:not(:disabled).fc-button-active { background:#fdfe00; color:#111; }
            .calendar-card .fc .fc-button-group { gap:2px; }
            .calendar-card .fc .fc-button-group > .fc-button { border-radius:.5rem; }
            .calendar-card .fc-theme-standard .fc-scrollgrid { overflow:hidden; border-radius:.75rem; }
            .calendar-card .fc .fc-col-header-cell { background:rgb(249 250 251); }
            .dark .calendar-card .fc .fc-col-header-cell { background:rgb(255 255 255 / .04); }
            .calendar-card .fc .fc-col-header-cell-cushion { padding:.65rem .35rem; color:rgb(75 85 99); font-size:.75rem; font-weight:700; text-transform:uppercase; }
            .dark .calendar-card .fc .fc-col-header-cell-cushion { color:rgb(156 163 175); }
            .calendar-card .fc .fc-daygrid-day-number { padding:.5rem; font-size:.75rem; font-weight:600; }
            .calendar-card .fc .fc-daygrid-day-frame { min-height:6.5rem; }
            .calendar-card .fc .fc-day-other .fc-daygrid-day-number { opacity:.35; }
            .calendar-card .fc .fc-event { margin:1px 3px; border:0; border-radius:.35rem; box-shadow:0 1px 2px rgb(0 0 0 / .08); cursor:pointer; }
            .calendar-card .fc .fc-event-main { overflow:hidden; padding:.18rem .35rem; }
            .calendar-card .fc .fc-event-title { display:block; overflow:hidden; font-size:.75rem; font-weight:600; text-overflow:ellipsis; white-space:nowrap; }
            .calendar-card .fc .fc-list { overflow:hidden; border-radius:.75rem; }
            .calendar-card .fc .fc-list-event:hover td { background:rgb(249 250 251); }
            .dark .calendar-card .fc .fc-list-event:hover td { background:rgb(255 255 255 / .05); }

            @media (max-width: 767px) {
                .calendar-card .fc .fc-toolbar { display:grid; grid-template-columns:1fr auto; gap:.75rem; }
                .calendar-card .fc .fc-toolbar-chunk:nth-child(1) { grid-column:1; grid-row:2; }
                .calendar-card .fc .fc-toolbar-chunk:nth-child(2) { grid-column:1 / -1; grid-row:1; }
                .calendar-card .fc .fc-toolbar-chunk:nth-child(3) { grid-column:2; grid-row:2; }
                .calendar-card .fc .fc-toolbar-title { font-size:1.125rem; }
                .calendar-card .fc .fc-button { padding:.45rem .6rem; }
                .calendar-card .fc .fc-dayGridMonth-button { display:none; }
                .calendar-card .fc .fc-daygrid-day-frame { min-height:4.5rem; }
                .calendar-card .fc .fc-list-event-title { min-width:0; }
            }
        </style>
    @endonce

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
                    initialView: window.matchMedia('(max-width: 767px)').matches ? 'listWeek' : 'dayGridMonth',
                    locale: 'az',
                    firstDay: 1,
                    height: 'auto',
                    dayMaxEvents: 3,
                    displayEventTime: false,
                    nowIndicator: true,
                    titleFormat: { year: 'numeric', month: 'long' },
                    noEventsContent: 'Bu tarix aralığında hadisə yoxdur',
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
                    eventDidMount: function (info) {
                        info.el.title = info.event.title;
                    },
                });

                calendar.render();
            }
        </script>
    @endscript
</x-filament-panels::page>
