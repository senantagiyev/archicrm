{{--
  Textarea. Same two families as <x-ui.input>; height/resize come from the caller.

  Example:
    <x-ui.textarea variant="b2b" class="h-24 resize-none">{{ $value }}</x-ui.textarea>
--}}
@props([
    'variant' => null,
])
<textarea {{ $attributes->merge(['class' => $variant === 'b2b' ? 'ui-control-b2b' : 'ui-control']) }}>{{ $slot }}</textarea>
