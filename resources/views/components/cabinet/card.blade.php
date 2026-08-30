{{--
  Cabinet card: a white 4px-radius surface with an optional heading block.

  Two head shapes:
    layout="stack" (default) — title over a wrapping description (leading 1.45)
    layout="row"             — title + one-line summary on the left, an action on the
                               right (the "add product" / "add showroom" cards)

  Example:
    <x-cabinet.card :title="t('...contact.title')" :desc="t('...contact.desc')" gap="gap-[18px]">
      …fields…
    </x-cabinet.card>

    <x-cabinet.card layout="row" :title="t('...list.title')" :desc="t('...list.summary')" gap="gap-3.5">
      <x-slot:action>
        <x-ui.button variant="primary" class="cab-btn-add">{{ t('...list.add') }}</x-ui.button>
      </x-slot:action>
      …rows…
    </x-cabinet.card>

  Props:
    title   — heading text (null → no heading block)
    desc    — description under the heading
    layout  — stack · row
    tag     — heading element (h2 default; h3 on the notifications page)
    gap     — gap utility between the card's children (differs per card)
    pad     — padding utility (default p-6)
--}}
@props([
    'title' => null,
    'desc' => null,
    'layout' => 'stack',
    'tag' => 'h2',
    'gap' => null,
    'pad' => 'p-6',
])
<div {{ $attributes->merge(['class' => trim('cab-card ' . $pad . ' ' . ($gap ?? ''))]) }}>
  @if ($title !== null)
    @if ($layout === 'row')
      <div class="cab-card-head-row">
        <div class="cab-card-head-txt">
          <{{ $tag }} class="cab-card-title">{{ $title }}</{{ $tag }}>
          @if ($desc !== null)<p class="cab-card-sub">{{ $desc }}</p>@endif
        </div>
        {{ $action ?? '' }}
      </div>
    @else
      <div class="cab-card-head">
        <{{ $tag }} class="cab-card-title">{{ $title }}</{{ $tag }}>
        @if ($desc !== null)<p class="cab-card-desc">{{ $desc }}</p>@endif
      </div>
    @endif
  @endif
  {{ $slot }}
</div>
