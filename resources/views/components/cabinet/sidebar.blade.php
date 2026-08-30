{{--
  Cabinet settings sidebar: the nav links + the "profile completeness" progress block.
  The six business pages shipped the same 30 lines of markup with six different class
  prefixes; this is the single copy. The business nav is the default, so those pages
  pass nothing; a different cabinet (the specialist one) passes its own `items`.

  Props:
    ns     — translation namespace; reads {ns}.nav.{key} for every item, the item's
             optional {ns}.nav.{count} and {ns}.progress.label
    active — the nav key that is ON
    items  — nav rows, each ['key' => …, 'route' => …, 'count' => …?]. `key` is both the
             active-state id and the lang key; `route` is a route NAME; `count` is an
             optional lang key rendered as the right-hand counter. Defaults to the six
             business rows — the nav order is part of the design, not page data.
    strong — nav keys that get data-strong="true" (legacy: business-profile-company
             marks "contact"); the styling for it stays in that page's CSS
    fill   — legacy width utility of the progress fill. Ignored: the bar is drawn from
             the completeness the signed-in profile actually scores.
--}}
@props([
    'ns' => null,
    'active' => null,
    'items' => null,
    'strong' => [],
    'fill' => null,
])
@php
    $businessUser = auth()->user();
    $showroomCount = $businessUser?->sellerProfile?->showrooms()->count() ?? 0;
    $productCount = $businessUser?->products()->count() ?? 0;
    $orderCount = $businessUser
        ? \App\Models\Order::query()
            ->whereHas('items.product', fn ($query) => $query->where('user_id', $businessUser->id))
            ->count()
        : 0;
    $items = $items ?: [
        ['key' => 'orders',        'route' => 'business.orders', 'count' => 'orders_count', 'count_value' => $orderCount],
        ['key' => 'company',       'route' => 'business.profile.company'],
        ['key' => 'contact',       'route' => 'business.profile.contact'],
        ['key' => 'showrooms',     'route' => 'business.profile.showrooms', 'count' => 'showrooms_count', 'count_value' => $showroomCount],
        ['key' => 'products',      'route' => 'business.profile.products',  'count' => 'products_count', 'count_value' => $productCount],
        ['key' => 'inventory',     'route' => 'business.inventory'],
        ['key' => 'security',      'route' => 'business.profile.security'],
    ];
    $items = collect($items)->map(function (array $item) use ($orderCount): array {
        if ($item['key'] === 'orders') {
            $item['count'] = 'orders_count';
            $item['count_value'] = $orderCount;
        }

        return $item;
    })->all();

    // Per-page namespaces own their labels; keys a page doesn't define (e.g. the
    // orders/inventory rows on the six legacy pages) fall back to business-cabinet.nav.*.
    $navLabel = fn (string $key) => \Illuminate\Support\Facades\Lang::has($ns . '.nav.' . $key)
        ? t($ns . '.nav.' . $key)
        : t('business-cabinet.nav.' . $key);

    // Completeness is computed from the profile that is actually filled in — the old
    // {ns}.progress.value string was a constant 85% / 78% for every account.
    $completeness = $businessUser?->specialistProfile !== null || $businessUser?->isMaster()
        ? \App\Support\ProfileCompleteness::forSpecialist($businessUser)
        : \App\Support\ProfileCompleteness::forSeller($businessUser);

    $nextKey = $completeness['next'];
    $hint = null;
    if ($nextKey !== null && \Illuminate\Support\Facades\Lang::has($nextKey)) {
        $hint = \Illuminate\Support\Facades\Lang::has('common.progress_next')
            ? t('common.progress_next', ['field' => t($nextKey)])
            : t($nextKey);
    }
@endphp
<div {{ $attributes->merge(['class' => 'cab-snav']) }}>
  @foreach ($items as $item)
    <a class="cab-snav-item"
       data-on="{{ $item['key'] === $active ? 'true' : 'false' }}"
       @if (in_array($item['key'], (array) $strong, true)) data-strong="true" @endif
       href="{{ route($item['route']) }}">
      <p class="lbl">{{ $navLabel($item['key']) }}</p>
      @isset($item['count'])
        @php $countKey = $ns . '.nav.' . $item['count']; @endphp
        @if (isset($item['count_value']) || \Illuminate\Support\Facades\Lang::has($countKey))
          <p class="cnt">{{ $item['count_value'] ?? t($countKey) }}</p>
        @endif
      @endisset
    </a>
  @endforeach

  <div class="cab-snav-prog" data-completeness="{{ $completeness['percent'] }}">
    <div class="row">
      <p class="l">{{ t($ns . '.progress.label') }}</p>
      <p class="v">{{ $completeness['percent'] }}%</p>
    </div>
    <x-ui.progress :width="$completeness['percent']" />
    @if ($hint)
      <p class="hint">{{ $hint }}</p>
    @endif
  </div>
</div>
