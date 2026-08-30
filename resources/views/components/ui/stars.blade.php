@props([
    'rating' => 0,
    'count' => 5,
    'icon' => '/assets/icon-star-yellow.svg',
])
@php
    $r = floatval($rating);
    $full = (int) floor($r);
    $half = ($r - $full) >= 0.25 && ($r - $full) < 0.75;
    $roundUp = ($r - $full) >= 0.75;
    if ($roundUp) $full++;
    $empty = (int) $count - $full - ($half ? 1 : 0);
@endphp
@for ($i = 0; $i < $full; $i++)<img src="{{ $icon }}" alt="" class="star-full">@endfor
@if ($half)<img src="{{ $icon }}" alt="" class="star-half" style="opacity:.45">@endif
@for ($i = 0; $i < $empty; $i++)<img src="{{ $icon }}" alt="" class="star-empty" style="opacity:.2">@endfor
