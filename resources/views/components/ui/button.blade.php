{{--
  Button / CTA. Tone comes from the design system (.ui-btn* in app.css); geometry and
  typography come from the caller, because the same yellow button is 42px tall in the
  cabinet and 54px on the auth pages. Sizes that DO repeat have a class of their own
  (.cab-btn-view, .cab-btn-add, .cab-btn-save, ...).

  Example:
    <x-ui.button variant="primary" class="cab-btn-save">{{ t('...save') }}</x-ui.button>
    <x-ui.button variant="outline" class="cab-btn-view" :href="route('business.profile')">…</x-ui.button>
    <x-ui.button variant="primary" class="h-[54px] text-lg font-semibold" type="submit">…</x-ui.button>

  Props:
    variant — primary (yellow) · dark (ink) · outline (white + border) ·
              ghost (borderless, danger text) · danger (red outline) ·
              on-ink (for the dark save bar)
    href    — renders an <a> instead of a <button>
    type    — <button type>; ignored when href is set (default "button")
    hover   — false → no transition and no hover state (business-profile-company only)
    icon    — optional icon path rendered before the label
--}}
@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'hover' => true,
    'icon' => null,
    'iconClass' => 'size-5',
])
@php
    $classes = 'ui-btn ui-btn-' . $variant;
@endphp
@if ($href !== null)
  <a {{ $attributes->merge(['class' => $classes]) }} href="{{ $href }}" data-hover="{{ $hover ? 'true' : 'false' }}">
    @if ($icon)<img class="{{ $iconClass }}" src="{{ $icon }}" alt="">@endif{{ $slot }}
  </a>
@else
  <button {{ $attributes->merge(['class' => $classes]) }} type="{{ $type }}" data-hover="{{ $hover ? 'true' : 'false' }}">
    @if ($icon)<img class="{{ $iconClass }}" src="{{ $icon }}" alt="">@endif{{ $slot }}
  </button>
@endif
