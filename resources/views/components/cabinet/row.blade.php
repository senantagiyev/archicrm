{{--
  Cabinet list row: the light-gray 4px strip used by the product list, the showroom
  list and the active-session list.

  Example:
    <x-cabinet.row data-cat="tile">
      <div class="…thumb…"></div>
      …
    </x-cabinet.row>

  `hidden` works even though the row is a flex box (ARCHITECTURE.md §6.5 is handled
  by .cab-row[hidden] in app.css).
--}}
<div {{ $attributes->merge(['class' => 'cab-row']) }}>{{ $slot }}</div>
