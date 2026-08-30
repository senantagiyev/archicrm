@props([
    'icon' => '📦',
    'title' => '',
    'description' => '',
    'actionText' => '',
    'actionUrl' => '',
])
<div {{ $attributes->merge(['class' => 'flex flex-col items-center gap-4 py-16 text-center']) }}>
  <span class="text-[48px]">{{ $icon }}</span>
  <h3 class="text-xl font-bold text-ink">{{ $title }}</h3>
  @if ($description)
    <p class="max-w-[380px] text-sm leading-[1.6] text-black/50">{{ $description }}</p>
  @endif
  @if ($actionText && $actionUrl)
    <x-ui.button variant="primary" :href="$actionUrl"
                 class="mt-2 h-[46px] rounded px-6 text-[15px] font-semibold">{{ $actionText }}</x-ui.button>
  @endif
  {{ $slot }}
</div>
