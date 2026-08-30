{{--
  Form field: a label above its control. The control goes in the slot, so the same
  wrapper serves input / textarea / select / a custom combobox.

  Example:
    <x-ui.field :label="t('login.form.identifier_label')" for="loginIdentifier">
      <x-ui.input id="loginIdentifier" type="text" :placeholder="t('...')" required />
    </x-ui.field>

    <x-ui.field variant="b2b" :label="t('business-register.form.name_label')">
      <x-ui.input variant="b2b" :placeholder="t('...')" />
    </x-ui.field>

  Props:
    label   — label text (omitted → no label element)
    for     — the control id the label points at
    variant — null (consumer: 14px medium black/70) · b2b (13px semibold ink)
    tag     — "label" (default) or "p"/"span" when the control is not a real form
              control and cannot be labelled (the onboarding city combobox)
--}}
@props([
    'label' => null,
    'for' => null,
    'variant' => null,
    'tag' => 'label',
])
@php
    $labelClass = $variant === 'b2b' ? 'ui-label-b2b' : 'ui-label';
@endphp
<div {{ $attributes->merge(['class' => 'ui-field']) }}>
  @if ($label !== null)
    <{{ $tag }} class="{{ $labelClass }}" @if ($for && $tag === 'label') for="{{ $for }}" @endif>{{ $label }}</{{ $tag }}>
  @endif
  {{ $slot }}
</div>
