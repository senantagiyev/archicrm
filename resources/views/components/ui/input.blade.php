{{--
  Text input.

  Example:
    <x-ui.input type="email" :placeholder="t('register.form.email_placeholder')" required />
    <x-ui.input variant="b2b" :value="t('business-profile-company.company.city_value')" />

  Props:
    variant — null (consumer: 1px black/20, 16px text, square) ·
              b2b (4px radius, 1px black/14, 14px text — business + cabinet pages)
    type    — input type (default "text")
--}}
@props([
    'variant' => null,
    'type' => 'text',
])
<input {{ $attributes->merge(['class' => $variant === 'b2b' ? 'ui-control-b2b' : 'ui-control']) }} type="{{ $type }}">
