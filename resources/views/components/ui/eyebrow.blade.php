{{--
  Eyebrow: the short yellow rule + an uppercase label. Repeated above almost every
  page heading (login · register · sell · blog · search · calculator ·
  business-register · business-profile · product · the login modal).

  Example:
    <x-ui.eyebrow :label="t('login.head.tag')" />
    <x-ui.eyebrow variant="b2b" :label="t('business-register.head.tag')" />

  Props:
    label   — the uppercase text (CSS applies the transform; never pass CAPS)
    variant — default (13px / 1.4px tracking, auth pages)
              lg     (14px, blog)
              flat   (2px square rule, black label, search)
              b2b    (12px semibold, yellow-line rule, business-register · calculator)
              kicker (12px medium, 2px square rule, business-profile)
--}}
@props([
    'label' => null,
    'variant' => null,
])
<div {{ $attributes->merge(['class' => 'ui-eyebrow']) }} @if ($variant) data-variant="{{ $variant }}" @endif>
  <span class="rule"></span>
  @if ($label !== null)<p>{{ $label }}</p>@endif
  {{ $slot }}
</div>
