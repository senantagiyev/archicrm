{{--
  Status badge / chip. Non-interactive (use <x-ui.chip> for the selectable one).
  Drives the "published" header chip, the product stock badge, the showroom state
  badge, the "this device" badge and the verified tax-id badge.

  Example:
    <x-ui.badge tone="ok" size="md" dot>{{ t('...status.published') }}</x-ui.badge>
    <x-ui.badge tone="warn" size="sm">{{ t('...stock_low') }}</x-ui.badge>

  Props:
    tone — ok (green #229653 on #e9f6ed) · warn · muted · green (--color-green tint)
    size — md (14px pad / 13px text) · sm (10px pad / 11px) · xs (8px pad / 10px)
    dot  — render the 8px status dot (inherits the tone color)
--}}
@props([
    'tone' => 'ok',
    'size' => 'md',
    'dot' => false,
])
<div {{ $attributes->merge(['class' => 'ui-badge']) }} data-tone="{{ $tone }}" data-size="{{ $size }}">
  @if ($dot)<span class="dot"></span>@endif
  <p>{{ $slot }}</p>
</div>
