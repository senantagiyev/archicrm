<?php

namespace App\Filament\Widgets;

use App\Enums\AccessLevel;
use App\Enums\Domain;
use App\Enums\ProjectStatus;
use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Finance\ProfitabilityService;
use App\Support\AccessMatrix;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** TZ v2.0 §8.24 — layihələr üzrə rentabellik (yalnız rəhbər/mühasib). */
class ProfitabilityWidget extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    /**
     * Cədvəl BÜTÜN layihələrin mənfəət/marjasını sətir-sətir açır — portfel
     * səviyyəli maliyyə. Şərt Profitability səhifəsindəki ilə eynidir
     * (Analitika = Baxış + Ödənişlər = Tam) ki, səhifə açılıb vidjet boş
     * qalmasın və əksinə. Rol adına baxmaq matris konstruktorunu yan keçirdi.
     */
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user !== null
            && AccessMatrix::allows($user, Domain::Analytics, AccessLevel::View)
            && AccessMatrix::allows($user, Domain::Payments, AccessLevel::Full);
    }

    public function table(Table $table): Table
    {
        $svc = app(ProfitabilityService::class);

        return $table
            ->heading('Layihələr üzrə rentabellik')
            ->query(fn (): Builder => Project::query()
                ->where('status', '!=', ProjectStatus::Archived->value)
                ->with('client')
                ->orderByDesc('created_at'))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Layihə')
                    ->description(fn (Project $r) => $r->client?->name),
                Tables\Columns\TextColumn::make('revenue')
                    ->label('Gəlir (yığılıb)')
                    ->state(fn (Project $r) => $svc->forProject($r)['revenue'])
                    ->money('AZN'),
                Tables\Columns\TextColumn::make('cost')
                    ->label('Xərc (əmək+xərclər)')
                    ->state(fn (Project $r) => $svc->forProject($r)['cost'])
                    ->money('AZN'),
                Tables\Columns\TextColumn::make('gross_profit')
                    ->label('Mənfəət')
                    ->state(fn (Project $r) => $svc->forProject($r)['gross_profit'])
                    ->money('AZN')
                    ->color(fn (Project $r) => $svc->forProject($r)['gross_profit'] >= 0 ? 'success' : 'danger')
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('margin')
                    ->label('Marja')
                    // The asterisk is the visible half of the tooltip: a tooltip
                    // nobody hovers is not a warning.
                    ->state(function (Project $r) use ($svc) {
                        $row = $svc->forProject($r);
                        $value = $row['margin'] === null ? '—' : $row['margin'].'%';

                        $incomplete = $row['uncosted_minutes'] > 0 || $row['uncosted_procurement'] > 0;

                        return $incomplete ? $value.' *' : $value;
                    })
                    ->tooltip(function (Project $r) use ($svc) {
                        $row = $svc->forProject($r);
                        $notes = [];

                        if ($row['uncosted_minutes'] > 0) {
                            $notes[] = round($row['uncosted_minutes'] / 60, 1).' saat tarifsiz işçi tərəfindən qeyd olunub.';
                        }

                        if ($row['uncosted_procurement'] > 0) {
                            $notes[] = number_format($row['uncosted_procurement'], 2).' ₼ komplektasiya müştəriyə fakturalanıb, '
                                .'amma nə satınalma sifarişi, nə xərc kimi maya dəyəri yazılmayıb.';
                        }

                        return $notes === [] ? null : 'Diqqət: '.implode(' ', $notes);
                    })
                    ->color(fn (Project $r) => match (true) {
                        $svc->forProject($r)['margin'] === null => 'gray',
                        $svc->forProject($r)['margin'] >= 0 => 'success',
                        default => 'danger',
                    }),
            ])
            ->recordUrl(fn (Project $record) => ProjectResource::getUrl('edit', ['record' => $record]))
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Aktiv layihə yoxdur');
    }
}
