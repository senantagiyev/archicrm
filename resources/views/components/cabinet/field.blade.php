{{--
  Cabinet form field: a 13px semibold label over its control, flexing to an equal
  column inside <div class="cab-field-row">.

  Example:
    <div class="cab-field-row">
      <x-cabinet.field :label="t('...person_label')" for="contact-person">
        <x-ui.input variant="b2b" id="contact-person" :value="t('...person_value')" />
      </x-cabinet.field>
      <x-cabinet.field :label="t('...role_label')" for="contact-role">…</x-cabinet.field>
    </div>

  Full-width row:
    <x-cabinet.field full :label="t('...address')">…</x-cabinet.field>

  Props:
    label — label text
    for   — id of the control
    full  — stretch to the full row instead of flexing (.cab-field-full)
    tag   — "label" (default) or "p"/"div" when the control is not a form control
    badge — optional named slot rendered next to the label (the "verified" tax-id badge)
--}}
@props([
    'label' => null,
    'for' => null,
    'full' => false,
    'tag' => 'label',
])
<div {{ $attributes->merge(['class' => 'cab-field' . ($full ? ' cab-field-full' : '')]) }}>
  @if ($label !== null)
    @isset($badge)
      <div class="flex shrink-0 items-center gap-2 overflow-hidden">
        <{{ $tag }} class="ui-label-b2b" @if ($for && $tag === 'label') for="{{ $for }}" @endif>{{ $label }}</{{ $tag }}>
        {{ $badge }}
      </div>
    @else
      <{{ $tag }} class="ui-label-b2b" @if ($for && $tag === 'label') for="{{ $for }}" @endif>{{ $label }}</{{ $tag }}>
    @endisset
  @endif
  {{ $slot }}
</div>
