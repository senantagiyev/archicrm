<x-portal.shell :title="t('portal.login_title')">
    <div class="mx-auto mt-12 max-w-md rounded-ds-md border border-black/10 bg-white p-8">
        <div class="mb-2 flex items-center gap-2">
            <span class="inline-block h-3 w-3 bg-yellow"></span>
            <h1 class="text-xl font-bold">{{ t('portal.login_title') }}</h1>
        </div>
        <p class="mb-6 text-sm text-black/60">{{ t('portal.login_hint') }}</p>

        <form method="post" action="{{ route('portal.login-link') }}" class="space-y-4">
            @csrf
            <div>
                <label for="email" class="mb-1.5 block text-[13px] font-semibold">{{ t('portal.email') }}</label>
                <input id="email" name="email" type="email" required autofocus
                    value="{{ old('email') }}"
                    class="h-11 w-full rounded-ds border border-black/20 px-3.5 text-sm outline-none focus:border-ink">
            </div>
            <button class="ui-btn ui-btn-primary h-11 w-full text-sm font-bold" data-hover="true">
                {{ t('portal.send_login_link') }}
            </button>
        </form>
    </div>
</x-portal.shell>
