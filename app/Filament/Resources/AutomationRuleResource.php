<?php

namespace App\Filament\Resources;

use App\Enums\AutomationPriority;
use App\Enums\StaffRole;
use App\Filament\Resources\AutomationRuleResource\Pages;
use App\Models\AutomationRule;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AutomationRuleResource extends Resource
{
    protected static ?string $model = AutomationRule::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static string|\UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?int $navigationSort = 80;

    protected static ?string $navigationLabel = 'Avtomatlaşdırmalar';

    protected static ?string $modelLabel = 'Avtomatlaşdırma qaydası';

    protected static ?string $pluralModelLabel = 'Avtomatlaşdırmalar';

    protected static ?string $recordTitleAttribute = 'name';

    /** Governance surface — Owner only (TZ §8.21 Admin zone). */
    public static function canAccess(): bool
    {
        return config('automations.enabled')
            && auth()->user()?->role === StaffRole::Owner;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Kod')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('trigger')
                    ->label('Trigger')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Əməliyyat')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('priority')
                    ->label('Prioritet')
                    ->badge()
                    ->formatStateUsing(fn (AutomationPriority $state) => $state->label())
                    ->color(fn (AutomationPriority $state) => $state->color()),
                Tables\Columns\ToggleColumn::make('enabled')
                    ->label('Aktiv'),
            ])
            ->defaultSort('id')
            ->paginated([25, 50, 'all'])
            ->filters([
                Tables\Filters\SelectFilter::make('priority')
                    ->label('Prioritet')
                    ->options(collect(AutomationPriority::cases())->mapWithKeys(fn ($p) => [$p->value => $p->label()])),
                Tables\Filters\TernaryFilter::make('enabled')
                    ->label('Aktivlik'),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAutomationRules::route('/'),
        ];
    }
}
