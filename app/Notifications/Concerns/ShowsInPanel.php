<?php

namespace App\Notifications\Concerns;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;

/**
 * İşçi bildirişinin baza sətri panelin zəng ikonunda GÖRÜNSÜN deyə Filament
 * formatında qurulur.
 *
 * Əvvəl bildirişlər sadə massiv yazırdı (`title`, `body`, `task_id`…). Filament-in
 * bildiriş paneli isə yalnız `format = filament` olan sətirləri göstərir —
 * nəticədə tapşırıq təyini, son tarix, gecikmə, brif və razılaşdırma
 * bildirişləri bazaya düşür, amma heç bir işçi onları görmürdü.
 *
 * Köhnə sahələr (`task_id`, `project_id`, `url`…) `$extra` ilə saxlanılır:
 * onları oxuyan kod (müştəri portalının bildiriş səhifəsi, testlər) dəyişmir.
 */
trait ShowsInPanel
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function panelMessage(
        string $title,
        ?string $body = null,
        ?string $url = null,
        string $icon = 'heroicon-o-bell',
        string $color = 'info',
        array $extra = [],
        string $actionLabel = 'Bax',
    ): array {
        $notification = FilamentNotification::make()
            ->title($title)
            ->body($body)
            ->icon($icon)
            ->iconColor($color);

        if (filled($url)) {
            $notification->actions([
                Action::make('open')->label($actionLabel)->url($url)->markAsRead(),
            ]);
        }

        return array_merge($notification->getDatabaseMessage(), $extra);
    }
}
