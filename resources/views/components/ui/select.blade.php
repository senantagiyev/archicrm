{{--
  Native <select>. The custom, JS-driven dropdowns (catalog sort, product filters,
  the onboarding city combobox) are NOT this component — they are listbox widgets.

  Example:
    <x-ui.select :placeholder="t('register.form.select_placeholder')"
                 :options="t('register.cities')" />

  Props:
    variant     — null (consumer) · b2b
    options     — array of option labels (value = label)
    placeholder — first, empty-valued option (omitted → none)
--}}
@props([
    'variant' => null,
    'options' => [],
    'placeholder' => null,
    'value' => null,
])
@php
    $resolvedOptions = $options instanceof \Illuminate\Support\Collection ? $options->all() : $options;
    // t() falls back to the raw key (a string) when a translation array is missing;
    // array_is_list() would then fatal, taking the whole page down with a 500.
    $resolvedOptions = is_array($resolvedOptions) ? $resolvedOptions : [];
    $optionsAreList = array_is_list($resolvedOptions);
@endphp
<select {{ $attributes->merge(['class' => $variant === 'b2b' ? 'ui-control-b2b' : 'ui-control']) }}>
  @if ($placeholder !== null)<option value="">{{ $placeholder }}</option>@endif
  @foreach ($resolvedOptions as $optionValue => $option)
    @php $resolvedValue = $optionsAreList ? $option : $optionValue; @endphp
    <option value="{{ $resolvedValue }}" @selected($value !== null && (string) $value === (string) $resolvedValue)>{{ $option }}</option>
  @endforeach
  {{ $slot }}
</select>
