{{--
  Thin progress bar (cabinet sidebar "profile completeness", onboarding side column).

  Example:
    <x-ui.progress :width="62" />
    <x-ui.progress class="max-w-[280px]" fill="w-1/2" />

  Props:
    width — filled share in percent (0–100). Rendered as an inline width, because the
            value is data and Tailwind cannot compile a class per possible percentage.
    fill  — legacy: utility classes that set the filled width (e.g. "w-1/2"). Only used
            when `width` is null.
--}}
@props([
    'width' => null,
    'fill' => null,
])
<div {{ $attributes->merge(['class' => 'ui-progress']) }}
     @if ($width !== null) role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ (int) $width }}" @endif>
  <div class="fill {{ $width === null ? $fill : '' }}"
       @if ($width !== null) style="width: {{ max(0, min(100, (int) $width)) }}%" @endif></div>
  {{ $slot }}
</div>
