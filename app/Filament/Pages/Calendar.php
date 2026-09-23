<?php

namespace App\Filament\Pages;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Support\AccessMatrix;
use Filament\Pages\Page;

/**
 * Unified calendar (TZ §8.7): meetings, stage plan-ends, task deadlines, payment
 * and invoice due dates. Events are fetched from route('calendar.events'),
 * already scoped to the user's accessible projects.
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
}
