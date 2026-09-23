<?php

namespace App\Filament\Resources;

use App\Enums\AccessLevel;
use App\Enums\AutomationPriority;
use App\Enums\Domain;
use App\Filament\Resources\AutomationRuleResource\Pages;
use App\Models\AutomationRule;
use App\Services\Automation\AutomationEngine;
use App\Support\AccessMatrix;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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

    /** Governance surface (TZ §8.21 Admin zone) — read from the matrix, not the role column. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return config('automations.enabled')
            && $user !== null
            && AccessMatrix::allows($user, Domain::OwnerDashboard, AccessLevel::Full);
    }

    /** Platform-wide defaults plus this studio's own overrides — nothing else. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->forTenant(auth()->user()?->tenant_id);
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
                    ->label('Aktiv')
                    // Copy-on-write: toggling a platform-wide rule creates this
                    // studio's own override instead of flipping the shared row
                    // for every studio on the installation.
                    ->updateStateUsing(function (AutomationRule $record, bool $state): void {
                        $tenantId = auth()->user()?->tenant_id;

                        if ($record->tenant_id === null && $tenantId !== null) {
                            // `forceFill` — `$fillable`-dan asılı olmayaraq
                            // studiya sahibliyi mütləq yazılsın. Bu sətir
                            // sükutla atılanda override yerinə İKİNCİ platforma
                            // sətri yaranırdı və bir studiyanın açarı bütün
                            // platformanın bildirişlərini söndürürdü.
                            $override = AutomationRule::query()
                                ->where('tenant_id', $tenantId)
                                ->where('code', $record->code)
                                ->first() ?? new AutomationRule;

                            $override->forceFill(
                                $record->only(['name', 'trigger', 'priority', 'conditions', 'actions'])
                                + ['tenant_id' => $tenantId, 'code' => $record->code, 'enabled' => $state]
                            )->save();
                        } else {
                            $record->update(['enabled' => $state]);
                        }

                        app(AutomationEngine::class)->flush();
                    }),
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
