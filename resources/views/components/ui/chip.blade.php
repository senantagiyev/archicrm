{{--
  Selectable chip with an optional checkbox box. Used by the cabinet communication
  languages and notification channels; the same shape fits any multi-select row.

  The label reserves its semibold width through a zero-height ghost copy
  (data-label), so toggling a chip never resizes it and never reflows the row.

  Example:
    <x-ui.chip :label="t('...languages.az')" :on="true" size="sm" tick="svg" />
    <x-ui.chip :label="t('...channels.email')" :on="$on" size="md" />

  Props:
    label — chip text (also written to data-label for the ghost width)
    on    — selected?
    size  — sm (13px, 9/14/12 padding) · md (14px, 10/16/14 padding)
    box   — render the checkbox square (default true)
    tick  — "css" (default, rotated-border tick) · "svg" (borderless box + inlined glyph)
    role  — ARIA role (default "checkbox"; pass "radio" for single-select groups)
--}}
@props([
    'label' => null,
    'on' => false,
    'size' => 'md',
    'box' => true,
    'tick' => 'css',
    'role' => 'checkbox',
])
@php
    $state = $on ? 'true' : 'false';
@endphp
<button
    {{ $attributes->merge(['class' => 'ui-chip', 'type' => 'button']) }}
    data-on="{{ $state }}"
    data-size="{{ $size }}"
    data-tick="{{ $tick }}"
    role="{{ $role }}"
    aria-checked="{{ $state }}"
>
  @if ($box)<span class="cbox"></span>@endif
  <span class="lbl" data-label="{{ $label }}">{{ $label }}</span>
  {{ $slot }}
</button>
