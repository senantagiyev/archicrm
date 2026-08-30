{{--
  Plain white surface: 4px radius, 1px black/10 border. Padding, gap and layout come
  from the caller, because they differ per card (p-5 · p-6 · px-7 py-6 · px-8 py-7).

  Example:
    <x-ui.card class="flex flex-col items-start gap-3 p-5">…</x-ui.card>

  For the cabinet use <x-cabinet.card>, which adds the heading block.
--}}
<div {{ $attributes->merge(['class' => 'ui-card']) }}>{{ $slot }}</div>
