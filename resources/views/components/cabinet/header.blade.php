{{--
  Cabinet page header: breadcrumbs + 34px title on the left, the "published" status
  badge + the "view profile" button on the right. Identical on all six cabinet pages
  (only the translation namespace differs).

  Rendered by <x-cabinet.shell>; call it directly only if a page needs a custom body.

  Props:
    ns       — translation namespace (see <x-cabinet.shell>)
    heading  — override the title (default {ns}.heading)
    viewHref — button target; false → a plain <button> with no destination
    hover    — false → no hover on the button (business-profile-company)
    showBack — false → hide the "‹ Geri" back button (shown by default)
--}}
@props([
    'ns' => null,
    'heading' => null,
    'viewHref' => null,
    'hover' => true,
    'showStatus' => false,
    'showViewButton' => true,
    'showBack' => true,
])
@php
    // The six lang files were written independently and use two key shapes for the
    // separator, the status label and the "view profile" label. Both are accepted so
    // no lang file has to change.
    $pick = function (string ...$keys) use ($ns) {
        foreach ($keys as $key) {
            $full = $ns . '.' . $key;
            if (\Illuminate\Support\Facades\Lang::has($full)) {
                return t($full);
            }
        }
        return $ns . '.' . $keys[0];
    };

    $href = $viewHref === null ? route('business.profile') : $viewHref;
@endphp
<div class="cab-head">
  <div class="cab-head-left">
    @if ($showBack)
      <x-cabinet.back />
    @endif
    <x-ui.breadcrumbs
        :sep="$pick('crumbs.sep', 'crumbs.separator')"
        :items="[
            ['label' => $pick('crumbs.panel')],
            ['label' => $pick('crumbs.current')],
        ]" />
    <p class="cab-title">{{ $heading ?? t($ns . '.heading') }}</p>
  </div>
  <div class="cab-head-right">
    @if ($showStatus)
      <x-ui.badge tone="ok" size="md" dot>{{ $pick('status.published', 'status') }}</x-ui.badge>
    @endif
    @if ($showViewButton)
    <x-ui.button
        variant="outline"
        class="cab-btn-view"
        :hover="$hover"
        :href="$href === false ? null : $href">{{ $pick('status.view_profile', 'view_profile') }}</x-ui.button>
    @endif
  </div>
</div>
