{{--
  Cabinet save bar: the dark strip at the bottom of every cabinet page — a status dot,
  an aria-live message, "cancel" and "save".

  Example:
    <x-cabinet.save-bar ns="business-profile-products" />

  JS contract (resources/js/pages/{slug}.js):
    .cab-save-bar            the bar; data-saved="true" turns the dot green
    .cab-save-bar .msg       the message node (aria-live="polite")
    data-saved-message       the text to swap in after a save
    [data-save]              the save button · [data-cancel] the cancel button

  Props:
    ns      — translation namespace; reads {ns}.save.unsaved|saved|saved_alert|cancel|save
    saved   — start in the saved state
    hover   — false → no hover on the two buttons (business-profile-company)
--}}
@props([
    'ns' => null,
    'saved' => false,
    'hover' => true,
])
@php
    $pick = function (string ...$keys) use ($ns) {
        foreach ($keys as $key) {
            $full = $ns . '.' . $key;
            if (\Illuminate\Support\Facades\Lang::has($full)) {
                return t($full);
            }
        }
        return $ns . '.' . $keys[0];
    };
@endphp
{{-- data-dirty starts false: the bar carries the Save button, but the "unsaved changes"
     warning must not greet a user who has not touched anything yet. --}}
<div {{ $attributes->merge(['class' => 'cab-save-bar']) }}
     data-saved="{{ $saved ? 'true' : 'false' }}"
     data-dirty="false"
     data-saved-message="{{ $pick('save.saved', 'save.saved_alert') }}">
  <div class="left">
    <span class="dot"></span>
    <p class="msg" aria-live="polite">{{ $pick('save.unsaved') }}</p>
  </div>
  <div class="right">
    <x-ui.button variant="on-ink" class="cab-btn-cancel" :hover="$hover" data-cancel>{{ $pick('save.cancel') }}</x-ui.button>
    <x-ui.button variant="primary" class="cab-btn-save" :hover="$hover" data-save>{{ $pick('save.save') }}</x-ui.button>
  </div>
</div>
