{{--
  Cabinet back button — "‹ Geri". Rendered by <x-cabinet.header> on every cabinet
  page (business + specialist); the buyer account pages include it directly.

  Behavior lives in resources/js/shared/back-button.js: history.back() when the
  visitor arrived from a same-origin page, otherwise the fallback URL, so the
  button never does nothing.

  Props:
    fallback — URL used when there is no same-origin history (default: home)
--}}
@props(['fallback' => null])
<button type="button"
    {{ $attributes->merge(['class' => 'cab-back']) }}
    data-back-button
    data-fallback="{{ $fallback ?? route('home') }}">
  <svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 4L6 8l4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
  <span>{{ t('common.back') }}</span>
</button>
