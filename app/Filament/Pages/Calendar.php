<?php

namespace App\Filament\Pages;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Filament\Resources\MeetingResource;
use App\Models\Meeting;
use App\Support\AccessMatrix;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Carbon;

/**
 * Unified calendar (TZ §8.7): meetings, stage plan-ends, task deadlines, payment
 * and invoice due dates. Events are fetched from route('calendar.events'),
 * already scoped to the user's accessible projects.
 *
 * Görüşlər təqvimin İÇİNDƏ idarə olunur (CRUD): «Görüş əlavə et» düyməsi və ya
 * boş günə klik yaratma modalını açır, görüşə klik isə redaktə/silmə modalını.
 * Forma və görünürlük `MeetingResource`-dan gəlir — qaydalar bir yerdədir.
 * Tapşırıq, mərhələ və ödəniş hadisələri öz səhifələrinə aparır.
 */
class Calendar extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Layihələr';

    protected static ?int $navigationSort = 15;

    protected static ?string $navigationLabel = 'Təqvim';

    protected static ?string $title = 'Təqvim';

    protected string $view = 'filament.pages.calendar';

    /**
     * Məzmun aşağı qatda (CalendarController) onsuz da kəsilir, amma TZ §5.20-ə
     * görə icazə UI gizlətməsi ilə deyil, serverdə tətbiq olunur — səhifə qatı da
     * bağlanmalıdır, əks halda bütün domenləri boş olan rol ekranı 200 ilə açır.
     *
     * Domen Mərhələ/Tapşırıq, səviyyə Baxış: təqvimin onurğası mərhələ plan
     * tarixləri və tapşırıq son tarixləridir. Standart altı rolun hamısında bu
     * domen ən azı Baxış səviyyəsindədir (mühasib = Baxış), ona görə heç kim
     * mövcud girişini itirmir; pul sətirləri isə kontrollerdə ayrıca
     * Ödənişlər = Baxış şərti ilə süzülür.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && AccessMatrix::allows($user, Domain::StagesTasks, AccessLevel::View);
    }

    public function canCreateMeetings(): bool
    {
        return auth()->user()?->can('create', Meeting::class) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [$this->createMeetingAction()];
    }

    /**
     * Yeni görüş. Boş günə klikləyəndə JS `{date: 'Y-m-d'}` ötürür və başlama
     * vaxtı həmin günün 10:00-ı ilə doldurulur.
     */
    public function createMeetingAction(): Action
    {
        return Action::make('createMeeting')
            ->label('Görüş əlavə et')
            ->icon('heroicon-o-plus')
            ->visible(fn () => $this->canCreateMeetings())
            ->authorize(fn () => $this->canCreateMeetings())
            ->modalHeading('Yeni görüş')
            ->modalWidth(Width::FourExtraLarge)
            ->schema(fn (Schema $schema) => MeetingResource::form($schema))
            ->fillForm(fn (array $arguments): array => [
                'starts_at' => $this->dayStart($arguments['date'] ?? null),
            ])
            ->action(function (array $data): void {
                abort_unless($this->canCreateMeetings(), 403);

                Meeting::create($data);

                Notification::make()->success()->title('Görüş təqvimə əlavə edildi')->send();
                $this->dispatch('calendar-refresh');
            });
    }

    /**
     * Mövcud görüşü redaktə və ya silmək. Görüş `MeetingResource`-un sorğusu
     * ilə tapılır — «yalnız öz layihələri» rolu yad layihənin görüşünü id ilə
     * aça bilməz (404); dəyişmək/silmək policy ilə ayrıca yoxlanılır.
     */
    public function editMeetingAction(): Action
    {
        return Action::make('editMeeting')
            ->modalHeading('Görüşü redaktə et')
            ->modalWidth(Width::FourExtraLarge)
            ->schema(fn (Schema $schema) => MeetingResource::form($schema))
            ->fillForm(fn (array $arguments): array => $this->meeting($arguments)->attributesToArray())
            ->disabledForm(fn (array $arguments): bool => ! $this->may('update', $arguments))
            ->modalSubmitActionLabel('Yadda saxla')
            ->modalSubmitAction(fn (Action $action, array $arguments) => $this->may('update', $arguments) ? $action : false)
            ->extraModalFooterActions(fn (Action $action, array $arguments): array => $this->may('delete', $arguments)
                ? [$action->makeModalSubmitAction('delete', arguments: ['delete' => true])
                    ->label('Sil')
                    ->color('danger')]
                : [])
            ->action(function (array $data, array $arguments): void {
                $meeting = $this->meeting($arguments);

                if ($arguments['delete'] ?? false) {
                    abort_unless($this->may('delete', $arguments), 403);
                    $meeting->delete();
                    Notification::make()->success()->title('Görüş silindi')->send();
                } else {
                    abort_unless($this->may('update', $arguments), 403);
                    $meeting->update($data);
                    Notification::make()->success()->title('Görüş yeniləndi')->send();
                }

                $this->dispatch('calendar-refresh');
            });
    }

    private function may(string $ability, array $arguments): bool
    {
        return auth()->user()?->can($ability, $this->meeting($arguments)) ?? false;
    }

    private function meeting(array $arguments): Meeting
    {
        return MeetingResource::getEloquentQuery()->findOrFail((int) ($arguments['meeting'] ?? 0));
    }

    private function dayStart(?string $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        try {
            return Carbon::parse($date)->setTime(10, 0)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
