{{--
  Native checkbox with an inline label (the "remember me" / "I agree" rows).
  The tick is the browser's, tinted with accent-color: --color-yellow.

  Example:
    <x-ui.checkbox>{{ t('login.form.remember') }}</x-ui.checkbox>
    <x-ui.checkbox required class="gap-2.5">…terms…</x-ui.checkbox>

  Attributes are merged onto the <label>; input-only attributes go through `input`.
--}}
@props([
    'checked' => false,
    'required' => false,
    'name' => null,
])
<label {{ $attributes->merge(['class' => 'ui-check-row']) }}>
  <input class="ui-check" type="checkbox"
         @if ($name) name="{{ $name }}" @endif
         @checked($checked) @required($required)>
  {{ $slot }}
</label>
