{{--
  Switch. The ON track is --color-ok (#229653) — standardised in phase 2; the yellow
  tone exists for the catalog "in stock only" filter family.

  Example:
    <x-ui.toggle :on="true" size="md" :aria-label="t('...types.order_title')" />
    <x-ui.toggle :on="$row['on']" size="sm" />

  Props:
    on    — ON state
    size  — md (44x24, knob 20px) · sm (40x22, knob 16px)
    tone  — ok (green, default) · yellow
    hover — brightness hover (default true)
--}}
@props([
    'on' => false,
    'size' => 'md',
    'tone' => 'ok',
    'hover' => true,
])
@php
    $state = $on ? 'true' : 'false';
@endphp
<button
    {{ $attributes->merge(['class' => 'ui-toggle', 'type' => 'button']) }}
    data-on="{{ $state }}"
    data-size="{{ $size }}"
    data-tone="{{ $tone }}"
    data-hover="{{ $hover ? 'true' : 'false' }}"
    aria-pressed="{{ $state }}"
><span class="knob"></span></button>
