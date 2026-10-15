{{--
    Dar ekranda layihə bölmələrinin yığcam menyusu (TZ «Layihə naviqasiyası»,
    meyar 13). Uzun üfüqi tab sətri `sm`-dən aşağı gizlədilir
    (theme.css → `.archi-project-tabs > .fi-tabs`), bu seçim isə eyni
    `activeRelationManager` Livewire xassəsini dəyişir — tab düyməsi ilə eyni yol.
--}}
<div class="archi-project-section-select">
    <label for="archi-project-section" class="archi-project-section-select-label">Bölmə</label>
    <select
        id="archi-project-section"
        x-data
        x-on:change="$wire.set('activeRelationManager', $event.target.value === '' ? null : $event.target.value)"
    >
        @foreach ($options as $key => $label)
            <option value="{{ $key }}" @selected((string) $key === $active)>{{ $label }}</option>
        @endforeach
    </select>
</div>
