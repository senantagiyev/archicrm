{{--
  Modal shell: full-screen overlay + a centred dialog + the close button. The overlay
  and the close button were byte-identical strings in the login modal and the sell
  success dialog, so they live in app.css (.ui-modal-overlay / .ui-modal-close).
  The dialog box itself stays a caller concern — its width, padding and entry
  animation differ between the two.

  Example:
    <x-ui.modal id="okOv" :close-label="t('sell.success.close')"
                dialog="w-full max-w-[480px] animate-[lmIn_0.26s_ease] bg-white px-9 py-10 text-center shadow-[-6px_6px_28px_rgba(0,0,0,0.22)]">
      …dialog content…
    </x-ui.modal>

  Props:
    dialog     — utility classes for the dialog box
    closeLabel — aria-label of the close button (null → no close button)
    on         — open on first paint?
--}}
@props([
    'dialog' => 'w-full max-w-[440px] bg-white p-9',
    'closeLabel' => null,
    'on' => false,
])
<div {{ $attributes->merge(['class' => 'ui-modal-overlay']) }} data-on="{{ $on ? 'true' : 'false' }}" role="dialog" aria-modal="true">
  <div class="relative {{ $dialog }}">
    @if ($closeLabel !== null)
      <button type="button" class="ui-modal-close" data-modal-close aria-label="{{ $closeLabel }}">&times;</button>
    @endif
    {{ $slot }}
  </div>
</div>
