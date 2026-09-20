<x-portal.shell :title="t('portal.nav_profile')" active="profile">
    <h1 class="mb-6 font-b2b text-[26px] font-extrabold tracking-tight">{{ t('portal.nav_profile') }}</h1>

    <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="rounded-[16px] border border-black/8 bg-white p-6">
            <div class="mb-6 flex items-center gap-4">
                <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-ink text-[20px] font-bold text-white">
                    {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                </span>
                <div class="min-w-0">
                    <p class="truncate text-[18px] font-bold">{{ $user->name }}</p>
                    <p class="truncate text-[14px] text-black/50">{{ $user->email }}</p>
                </div>
            </div>

            <dl class="divide-y divide-black/8 border-t border-black/8">
                <div class="flex items-start justify-between gap-4 py-3">
                    <dt class="text-[14px] text-black/50">{{ t('portal.profile_name') }}</dt>
                    <dd class="text-right text-[14px] font-semibold">{{ $user->name }}</dd>
                </div>
                <div class="flex items-start justify-between gap-4 py-3">
                    <dt class="text-[14px] text-black/50">{{ t('portal.email') }}</dt>
                    <dd class="min-w-0 break-all text-right text-[14px] font-semibold">{{ $user->email }}</dd>
                </div>
                @if ($client)
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-[14px] text-black/50">{{ t('portal.profile_client') }}</dt>
                        <dd class="text-right text-[14px] font-semibold">{{ $client->name }}</dd>
                    </div>
                @endif
                <div class="flex items-start justify-between gap-4 py-3">
                    <dt class="text-[14px] text-black/50">{{ t('portal.my_projects') }}</dt>
                    <dd class="text-right text-[14px] font-semibold">{{ $projectCount }}</dd>
                </div>
                @if ($user->last_login_at)
                    <div class="flex items-start justify-between gap-4 py-3">
                        <dt class="text-[14px] text-black/50">{{ t('portal.profile_last_login') }}</dt>
                        <dd class="text-right text-[14px] font-semibold">{{ $user->last_login_at->format('d.m.Y H:i') }}</dd>
                    </div>
                @endif
            </dl>

            {{-- Parol sahəsi QƏSDƏN yoxdur: portal magic-link ilə işləyir. --}}
            <p class="mt-5 rounded-[12px] bg-neutral-soft px-4 py-3 text-[13px] leading-relaxed text-black/55">
                {{ t('portal.profile_passwordless') }}
            </p>
        </div>

        <div class="space-y-4">
            <div class="rounded-[16px] border border-black/8 bg-white p-6">
                <p class="mb-3 text-[13px] font-semibold">{{ t('portal.profile_language') }}</p>
                <form method="post" action="{{ route('locale.switch') }}" class="grid gap-2">
                    @csrf
                    @foreach (['az' => 'Azərbaycanca', 'ru' => 'Русский', 'en' => 'English'] as $loc => $localeLabel)
                        <button name="locale" value="{{ $loc }}"
                            @if (app()->getLocale() === $loc) aria-current="true" @endif
                            class="flex items-center justify-between rounded-[12px] px-4 py-2.5 text-[14px] font-semibold transition-colors
                                   {{ app()->getLocale() === $loc ? 'bg-yellow text-ink' : 'bg-neutral-soft text-black/55 hover:text-ink' }}">
                            <span>{{ $localeLabel }}</span>
                            @if (app()->getLocale() === $loc)
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m20 6-11 11-5-5"/></svg>
                            @endif
                        </button>
                    @endforeach
                </form>
            </div>

            <div class="rounded-[16px] border border-black/8 bg-white p-6">
                <form method="post" action="{{ route('portal.logout') }}">
                    @csrf
                    <button class="ui-btn ui-btn-outline h-11 w-full px-5 text-[14px] font-bold" data-hover="true">
                        {{ t('portal.logout') }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</x-portal.shell>
