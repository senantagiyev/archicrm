<?php

namespace App\Filament\Widgets;

use App\Enums\ProjectStatus;
use App\Enums\StaffRole;
use App\Filament\Resources\ProjectResource;
use App\Models\Project;
use App\Services\Finance\ProfitabilityService;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/** TZ v2.0 §8.24 — layihələr üzrə rentabellik (yalnız rəhbər/mühasib). */
class ProfitabilityWidget extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $role = auth()->user()?->role;

        return auth()->user()?->isOwner()
            || $role === StaffRole::Accountant;
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
                    ->state(fn (Project $r) => $svc->forProject($r)['margin'].'%')
                    ->color(fn (Project $r) => $svc->forProject($r)['margin'] >= 0 ? 'success' : 'danger'),
            ])
            ->recordUrl(fn (Project $record) => ProjectResource::getUrl('edit', ['record' => $record]))
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Aktiv layihə yoxdur');
    }
}
